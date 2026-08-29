<?php
declare(strict_types=1);
// phpcs:disable WordPress.PHP.DevelopmentFunctions -- error_reporting()/set_error_handler() are this plugin's purpose: it captures PHP errors, chaining any previous handler
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec -- the fire-and-forget ingest call needs millisecond timeouts (300 ms connect / 1 s total) the WP HTTP API cannot express; wp_remote_post() is the fallback when curl is missing

namespace OvosConsole;

use ErrorException;
use Throwable;

use function array_map;
use function array_slice;
use function array_values;
use function count;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;
use function defined;
use function error_get_last;
use function error_reporting;
use function function_exists;
use function in_array;
use function json_encode;
use function mb_substr;
use function register_shutdown_function;
use function rtrim;
use function session_id;
use function session_status;
use function set_error_handler;
use function spl_object_id;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

use const PHP_SESSION_ACTIVE;
use const PHP_URL_HOST;

/**
 * Reports collected errors to a central ovos/console instance —
 * standalone WordPress port of the php-library Console\Sender.
 *
 * Fire-and-forget by contract: every public method swallows all
 * failures and the single HTTP call happens once per request from
 * the shutdown handler with a hard timeout — the console must never
 * break or noticeably slow the host site.
 *
 * Non-fatal errors are captured via a chained set_error_handler
 * (error_reporting() and the @ operator are respected, behavior is
 * never altered); fatals — uncaught exceptions included — via
 * error_get_last() at shutdown. There is deliberately no
 * set_exception_handler: replacing it would change WordPress'
 * fatal handling (recovery mode, display).
 */
class Sender
{
	/**
	 * The closed security-event vocabulary — must mirror the console's
	 * App::SECURITY_KINDS exactly; the console refuses unknown kinds
	 * wholesale, so this list only grows in a deliberate two-sided change
	 */
	public const SECURITY_KINDS = [
		'auth_failure',
		'auth_success',
		'csrf_reject',
		'permission_denied',
		'rate_limited',
		'validation_refused',
		'privileged_action',
	];
	
	/**
	 * Fixed 60-second cap on security-event reports, so a credential-stuffing
	 * run cannot turn this reporter into the flood it is meant to surface
	 */
	protected const MAX_SECURITY_PER_MINUTE = 60;
	
	protected const MAX_QUEUE = 100;
	
	protected const MAX_BATCH = 50;
	
	protected const MAX_BODY = 262144;
	
	protected const FATAL_TYPES = [
		E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR,
	];
	
	/**
	 * Queued payloads, keyed by spl_object_id for throwables so one
	 * object is never double-reported
	 */
	protected array $queue = [];
	
	protected bool $flushing = false;
	
	/**
	 * @var callable|null
	 */
	protected $previousErrorHandler = null;
	
	public function __construct(
		protected Config $config,
	)
	{
	}
	
	public function register(): void
	{
		$this->previousErrorHandler = set_error_handler([$this, 'handleError']);
		
		register_shutdown_function([$this, 'handleShutdown']);
	}
	
	public function isEnabled(): bool
	{
		return $this->config->enabled()
			&& $this->config->url() !== ''
			&& $this->config->apiKey() !== '';
	}
	
	public function captureException(
		Throwable $event,
		array $extra = [],
		?int $priority = null,
	): static
	{
		try
		{
			$this->queue[spl_object_id($event)] =
				Payload::fromThrowable($event, $priority, $extra);
		}
		catch(Throwable)
		{
			// never break the host site
		}
		
		return $this;
	}
	
	public function captureMessage(
		string $message,
		int $priority = 5,
		array $extra = [],
	): static
	{
		try
		{
			$this->queue[] = Payload::fromMessage($message, $priority, $extra);
		}
		catch(Throwable)
		{
			// never break the host site
		}
		
		return $this;
	}
	
	/**
	 * Reports a not-found access event as a type=404 report (priority 6, INFO).
	 * The console groups these apart from real errors, never turns them into
	 * issues, and its per-project report_404 switch decides acceptance. No-op
	 * unless report_404 is enabled here. The path (query stripped and scrubbed
	 * with the request patterns) is the message so distinct probes stay distinct
	 * while one hammered path folds together; the report ships from the shutdown
	 * flush like any other.
	 */
	public function capture404(
		string $path = '',
		array $extra = [],
	): void
	{
		if($this->config->report404() === false
			|| count($this->queue) >= self::MAX_QUEUE)
		{
			return;
		}
		
		try
		{
			$path = Redactor::scrubUrl($path !== '' ? $path : $this->server('REQUEST_URI'));
			
			$mark = strpos($path, '?');
			if($mark !== false)
			{
				$path = substr($path, 0, $mark);
			}
			
			$payload = Payload::fromMessage('404 Not Found: ' . mb_substr($path, 0, 512), 6, $extra);
			$payload['type'] = '404';
			
			$this->queue[] = $payload;
		}
		catch(Throwable)
		{
			// never break the host site
		}
	}
	
	/**
	 * Reports a refused action as a type=security event (priority 6, INFO) —
	 * what was REFUSED, beside what broke: failed logins, rejected nonce
	 * checks, forbidden REST calls, sensitive admin changes. $kind must come
	 * from SECURITY_KINDS (the console refuses unknown kinds; anything else
	 * is a silent no-op) and rides events[0].className, the same slot an
	 * exception's class occupies. No-op unless the security_events setting
	 * is on; rate-capped so an attack cannot flood its own report channel.
	 * The console accepts these apart from the project's severity threshold
	 * (a kind, not a severity) but has its own per-project off switch.
	 */
	public function reportRefusal(
		string $kind,
		string $message = '',
		array $extra = [],
	): void
	{
		if($this->config->securityEvents() === false
			|| in_array($kind, self::SECURITY_KINDS, true) === false
			|| count($this->queue) >= self::MAX_QUEUE
			|| $this->allowSecurity() === false)
		{
			return;
		}
		
		try
		{
			$message = $message !== ''
				? Redactor::maskEmails(mb_substr($message, 0, 512))
				: $kind;
				
			$payload = Payload::fromMessage($message, 6, $extra);
			$payload['type'] = 'security';
			$payload['events'] = [
				[
					'message' => $message,
					'className' => $kind,
					'file' => '',
					'line' => 0,
					'backtrace' => '',
					'previous' => false,
				],
			];
			
			$this->queue[] = $payload;
		}
		catch(Throwable)
		{
			// never break the host site
		}
	}
	
	/**
	 * Fixed 60-second window cap on security reports (a transient counter,
	 * same mechanism as the 404 throttle)
	 */
	protected function allowSecurity(): bool
	{
		$key = 'ovos_console_security_rate';
		$count = (int)get_transient($key);
		
		if($count >= self::MAX_SECURITY_PER_MINUTE)
		{
			return false;
		}
		
		set_transient($key, $count + 1, 60);
		
		return true;
	}
	
	/**
	 * set_error_handler callback — captures, then hands over to the
	 * previous handler (or to PHP's own, by returning false)
	 */
	public function handleError(
		int $severity,
		string $message,
		string $file = '',
		int $line = 0,
	): bool
	{
		try
		{
			if((error_reporting() & $severity) !== 0
				&& Payload::severityPriority($severity) <= $this->config->logLevel()
				&& count($this->queue) < self::MAX_QUEUE)
			{
				$this->captureException(
					new ErrorException($message, 0, $severity, $file, $line));
			}
		}
		catch(Throwable)
		{
			// never break the host site
		}
		
		if($this->previousErrorHandler !== null)
		{
			return (bool)($this->previousErrorHandler)($severity, $message, $file, $line);
		}
		
		return false;
	}
	
	public function handleShutdown(): void
	{
		try
		{
			$error = error_get_last();
			
			if($error !== null
				&& in_array((int)($error['type'] ?? 0), self::FATAL_TYPES, true))
			{
				$this->queue[] = Payload::fromFatal($error);
			}
		}
		catch(Throwable)
		{
			// never break the host site
		}
		
		$this->flush();
	}
	
	/**
	 * Builds and posts the batch — called once from the shutdown handler
	 * after the response went out
	 */
	public function flush(): void
	{
		if($this->flushing || $this->isEnabled() === false)
		{
			return;
		}
		
		$this->flushing = true;
		
		try
		{
			$logLevel = $this->config->logLevel();
			$context = null;
			
			$errors = [];
			
			foreach($this->queue as $payload)
			{
				// 404 access events and security events ride the INFO band but
				// are KINDS, not severities — the log_level gate (a severity
				// filter) must not drop them
				$type = (string)($payload['type'] ?? '');
				if($type !== '404' && $type !== 'security'
					&& $payload['priority'] > $logLevel)
				{
					continue;
				}
				
				$context ??= $this->buildContext();
				
				$errors[] = $this->decorate($payload, $context);
				
				if(count($errors) === self::MAX_BATCH)
				{
					break;
				}
			}
			
			if($errors !== [])
			{
				$json = $this->encode($errors);
				
				while(strlen($json) > self::MAX_BODY && count($errors) > 1)
				{
					$errors = array_slice($errors, 0, (int)(count($errors) / 2));
					$json = $this->encode($errors);
				}
				
				$this->send($json);
			}
		}
		catch(Throwable)
		{
			// silence is the contract
		}
		finally
		{
			$this->queue = [];
			$this->flushing = false;
		}
	}
	
	/**
	 * Posts one synchronous test error and returns the HTTP status —
	 * used by the settings page, the only non-silent path
	 */
	public function sendTest(): int
	{
		$payload = $this->decorate(
			Payload::fromMessage('Test error from the ovos console WordPress plugin', 3),
			$this->buildContext());
		
		$response = wp_remote_post($this->config->url() . '/api/v1/ingest', [
			'timeout' => 5,
			'headers' => [
				'Content-Type' => 'application/json',
				'X-Console-Key' => $this->config->apiKey(),
			],
			'body' => $this->encode([$payload]),
			'sslverify' => $this->verifyTls(),
		]);
		
		if(is_wp_error($response))
		{
			return 0;
		}
		
		return (int)wp_remote_retrieve_response_code($response);
	}
	
	/**
	 * Merges context, WP extras and the release label into a payload
	 */
	protected function decorate(
		array $payload,
		array $context,
	): array
	{
		// respect a type the payload already carries (404); else the request type
		$payload['type'] ??= $context['type'];
		$payload['context'] = $context['context']
			+ ['extra' => Redactor::scrub($payload['extra']) + $this->buildWpExtra($payload)];
		unset($payload['extra']);
		
		$release = $this->config->release();
		
		if($release !== '')
		{
			$payload['release'] = $release;
		}
		
		return $payload;
	}
	
	/**
	 * @return array{type: string, context: array}
	 */
	protected function buildContext(): array
	{
		$isCli = PHP_SAPI === 'cli' || defined('WP_CLI');
		
		$context = [
			'dir' => defined('ABSPATH') ? rtrim(ABSPATH, '/\\') : '',
			// correlates every error of this request in the console — across
			// services when an inbound traceparent is propagated
			'traceId' => Trace::id(),
		];
		
		if($isCli)
		{
			$context['host'] = $this->homeHost();
			$context['args'] = isset($_SERVER['argv'])
				? Redactor::scrubArgs(array_values(
					array_map('sanitize_text_field', wp_unslash((array)$_SERVER['argv']))))
				: [];
		}
		else
		{
			$host = $this->server('HTTP_HOST');
			
			$context['host'] = $host !== '' ? $host : $this->homeHost();
			// secrets and e-mails travel in query strings too — scrub the
			// url copies the same way request.get is scrubbed
			$context['uri'] = Redactor::scrubUrl($this->server('REQUEST_URI'));
			$context['method'] = $this->server('REQUEST_METHOD');
			$context['referer'] = Redactor::scrubUrl($this->server('HTTP_REFERER'));
			$context['ip'] = $this->server('REMOTE_ADDR');
			$context['ua'] = $this->server('HTTP_USER_AGENT');
			
			if(session_status() === PHP_SESSION_ACTIVE)
			{
				$context['sessionId'] = (string)session_id();
			}
			
			if(function_exists('get_current_user_id'))
			{
				$userId = (int)get_current_user_id();
				
				if($userId > 0)
				{
					$context['userId'] = (string)$userId;
				}
			}
			
			$context['request'] = $this->buildRequest();
		}
		
		return [
			'type' => $isCli ? 'cli' : 'http',
			'context' => $context,
		];
	}
	
	/**
	 * One $_SERVER string, unslashed and sanitized
	 */
	protected function server(
		string $key,
	): string
	{
		return isset($_SERVER[$key])
			? sanitize_text_field(wp_unslash((string)$_SERVER[$key]))
			: '';
	}
	
	/**
	 * Request variables, redacted before sending (the console scrubs
	 * again server-side as a backstop)
	 */
	protected function buildRequest(): array
	{
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- request variables are collected as diagnostic payload, never processed; secrets are redacted here and again server-side
		$request = [];
		
		if(!empty($_GET))
		{
			$request['get'] = Redactor::scrub(wp_unslash((array)$_GET));
		}
		
		if(!empty($_POST))
		{
			$request['post'] = Redactor::scrub(wp_unslash((array)$_POST));
		}
		// phpcs:enable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		
		return $request;
	}
	
	/**
	 * WordPress diagnostics per payload: core version, active theme and
	 * source attribution — which plugin/theme the error file lives in
	 */
	protected function buildWpExtra(
		array $payload,
	): array
	{
		$extra = [
			'wpVersion' => (string)($GLOBALS['wp_version'] ?? ''),
		];
		
		if(function_exists('get_stylesheet'))
		{
			$extra['theme'] = (string)get_stylesheet();
		}
		
		$source = $this->sourceFor((string)($payload['events'][0]['file'] ?? ''));
		
		if($source !== '')
		{
			$extra['source'] = $source;
		}
		
		return $extra;
	}
	
	/**
	 * Attributes an error file to the plugin or theme it lives in
	 */
	protected function sourceFor(
		string $file,
	): string
	{
		if($file === '')
		{
			return '';
		}
		
		$file = str_replace('\\', '/', $file);
		
		$roots = [
			'plugin' => defined('WP_PLUGIN_DIR') ? (string)WP_PLUGIN_DIR : '',
			'mu-plugin' => defined('WPMU_PLUGIN_DIR') ? (string)WPMU_PLUGIN_DIR : '',
			'theme' => function_exists('get_theme_root') ? (string)get_theme_root() : '',
		];
		
		foreach($roots as $type => $root)
		{
			if($root === '')
			{
				continue;
			}
			
			$root = rtrim(str_replace('\\', '/', $root), '/') . '/';
			
			if(str_starts_with($file, $root))
			{
				$segment = substr($file, strlen($root));
				$slash = strpos($segment, '/');
				
				return $type . ':' . ($slash === false ? $segment : substr($segment, 0, $slash));
			}
		}
		
		return 'core';
	}
	
	protected function homeHost(): string
	{
		if(function_exists('home_url') === false)
		{
			return '';
		}
		
		$host = wp_parse_url((string)home_url(), PHP_URL_HOST);
		
		return $host === false || $host === null ? '' : (string)$host;
	}
	
	protected function encode(
		array $errors,
	): string
	{
		return (string)json_encode($errors,
			JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
	}
	
	/**
	 * TLS peer verification for the ingest call — on by default; a console
	 * behind a self-signed certificate (intranet instances) opts out via
	 * add_filter('ovos_console_sslverify', '__return_false')
	 */
	protected function verifyTls(): bool
	{
		return (bool)apply_filters('ovos_console_sslverify', true);
	}
	
	protected function send(
		string $json,
	): void
	{
		// the response is already sent — release the connection so the
		// HTTP call is invisible to the end user
		if(function_exists('fastcgi_finish_request') && PHP_SAPI !== 'cli')
		{
			@fastcgi_finish_request();
		}
		
		$endpoint = $this->config->url() . '/api/v1/ingest';
		$verify = $this->verifyTls();
		
		if(function_exists('curl_init'))
		{
			$handle = curl_init($endpoint);
			
			curl_setopt_array($handle, [
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $json,
				CURLOPT_HTTPHEADER => [
					'Content-Type: application/json',
					'X-Console-Key: ' . $this->config->apiKey(),
				],
				CURLOPT_RETURNTRANSFER => true,
				// a libcurl without the threaded resolver times sub-second
				// timeouts via SIGALRM, which cannot do sub-second at all —
				// it refuses with errno 28 BEFORE even resolving, losing
				// every batch. NOSIGNAL switches to poll-based timing, where
				// the 300ms connect bound works; only the DNS phase itself
				// is then bounded by the system resolver instead.
				CURLOPT_NOSIGNAL => true,
				CURLOPT_CONNECTTIMEOUT_MS => 300,
				CURLOPT_TIMEOUT_MS => 1000,
				CURLOPT_SSL_VERIFYPEER => $verify,
				CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
			]);
			
			curl_exec($handle);
			
			return;
		}
		
		wp_remote_post($endpoint, [
			'timeout' => 1,
			'headers' => [
				'Content-Type' => 'application/json',
				'X-Console-Key' => $this->config->apiKey(),
			],
			'body' => $json,
			'sslverify' => $verify,
		]);
	}
}
