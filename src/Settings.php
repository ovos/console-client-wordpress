<?php
declare(strict_types=1);

namespace Ovos\Console;

use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function max;
use function mb_substr;
use function min;
use function sprintf;
use function trim;

/**
 * Settings → ovos console.
 *
 * Values locked by an OVOS_CONSOLE_* constant in wp-config.php render
 * disabled and keep their stored value on save; the API key is
 * write-only (blank keeps the stored key).
 */
class Settings
{
	public const PAGE = 'ovos-console';
	
	protected const LEVELS = [
		0 => 'emergency',
		1 => 'alert',
		2 => 'critical',
		3 => 'error',
		4 => 'warning',
		5 => 'notice',
		6 => 'info',
		7 => 'debug',
	];
	
	public function __construct(
		protected Config $config,
		protected Sender $sender,
		protected string $file,
		protected ScanRunner $runner,
	)
	{
	}
	
	public function register(): void
	{
		add_action('admin_menu', [$this, 'addPage']);
		add_action('admin_init', [$this, 'registerSetting']);
		add_action('admin_post_ovos_console_test', [$this, 'handleTest']);
		add_filter('plugin_action_links_' . plugin_basename($this->file),
			[$this, 'actionLinks']);
	}
	
	public function actionLinks(
		array $links,
	): array
	{
		$url = admin_url('options-general.php?page=' . self::PAGE);
		
		array_unshift($links,
			'<a href="' . esc_url($url) . '">'
			. esc_html__('Settings', 'ovos-console') . '</a>');
			
		return $links;
	}
	
	public function addPage(): void
	{
		add_options_page(
			__('ovos console', 'ovos-console'),
			__('ovos console', 'ovos-console'),
			'manage_options',
			self::PAGE,
			[$this, 'renderPage']);
	}
	
	public function registerSetting(): void
	{
		register_setting('ovos_console', Config::OPTION, [
			'type' => 'array',
			'sanitize_callback' => [$this, 'sanitize'],
			'default' => Config::DEFAULTS,
		]);
	}
	
	/**
	 * @param mixed $input
	 */
	public function sanitize(
		$input,
	): array
	{
		$input = is_array($input) ? $input : [];
		$stored = (array)get_option(Config::OPTION, []);
		
		$clean = [
			'enabled' => $this->truthy($input['enabled'] ?? ''),
			'url' => esc_url_raw(trim((string)($input['url'] ?? ''))),
			'api_key' => trim((string)($input['api_key'] ?? '')),
			'log_level' => max(0, min(7, (int)($input['log_level'] ?? Config::DEFAULTS['log_level']))),
			'report_404' => $this->truthy($input['report_404'] ?? ''),
			'rollups' => $this->truthy($input['rollups'] ?? ''),
			'security_events' => $this->truthy($input['security_events'] ?? ''),
			'inventory' => $this->truthy($input['inventory'] ?? ''),
			'scan' => $this->truthy($input['scan'] ?? ''),
			'scan_interval' => in_array((int)($input['scan_interval'] ?? 7), [1, 7], true)
				? (int)$input['scan_interval']
				: 7,
			'release' => mb_substr(sanitize_text_field((string)($input['release'] ?? '')), 0, 64),
			'environment' => mb_substr(sanitize_text_field((string)($input['environment'] ?? '')), 0, 64),
			'js_enabled' => $this->truthy($input['js_enabled'] ?? ''),
			'js_key' => sanitize_text_field((string)($input['js_key'] ?? '')),
			'js_trace' => $this->truthy($input['js_trace'] ?? ''),
			'snapshot' => $this->truthy($input['snapshot'] ?? ''),
			'snapshot_styles' => $this->truthy($input['snapshot_styles'] ?? ''),
			'js_admin' => $this->truthy($input['js_admin'] ?? ''),
		];
		
		// write-only: a blank key keeps the stored one
		if($clean['api_key'] === '')
		{
			$clean['api_key'] = (string)($stored['api_key'] ?? '');
		}
		
		// constants win — never let the form overwrite a locked value
		foreach(array_keys($clean) as $key)
		{
			if($this->config->isConstant($key))
			{
				$clean[$key] = $stored[$key] ?? Config::DEFAULTS[$key];
			}
		}
		
		return $clean;
	}
	
	/**
	 * Checkbox value → bool, tolerating an already-sanitized boolean: on the
	 * first-ever save core routes update_option() into add_option(), which
	 * sanitizes the sanitized array a second time (trac #21989) — a strict
	 * '1' comparison would wipe every checked box back to false.
	 */
	protected function truthy(
		mixed $value,
	): bool
	{
		return $value === true || $value === '1';
	}
	
	public function renderPage(): void
	{
		if(current_user_can('manage_options') === false)
		{
			return;
		}
		
		$this->renderTestNotice();
		$this->renderScanNotice();
		
		echo '<div class="wrap"><h1>' . esc_html__('ovos console', 'ovos-console') . '</h1>';
		
		echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
		
		settings_fields('ovos_console');
		
		echo '<h2>' . esc_html__('Console connection', 'ovos-console') . '</h2>';
		echo '<p>' . esc_html__('Create a project in your console instance and paste its keys here. PHP errors use the secret API key; browser errors use the public JS key — allowlist this site\'s origin in the project settings.', 'ovos-console') . '</p>';
		echo '<table class="form-table" role="presentation">';
		
		$this->checkboxField('enabled',
			__('Enabled', 'ovos-console'),
			__('Master switch for PHP and browser error reporting.', 'ovos-console'));
		$this->inputField('url',
			__('Console URL', 'ovos-console'), 'url', 'https://console.example');
		$this->inputField('api_key',
			__('API key', 'ovos-console'), 'password', '',
			__('The project\'s secret api_key. Stored value is kept when left blank.', 'ovos-console'));
		$this->levelField();
		$this->checkboxField('report_404',
			__('Report 404s', 'ovos-console'),
			__('Report front-end not-found (404) requests as access events. Surfaces scanner and broken-link traffic in the console, grouped apart from real errors and never creating issues. Rate-limited, and static-asset 404s are ignored.', 'ovos-console'));
		$this->checkboxField('rollups',
			__('Traffic rollups', 'ovos-console'),
			__('Send anonymous per-minute traffic counters (request totals split by status, method, resolved page type and logged-in state — never URLs or visitor data), so the console can read error and probe counts as rates. Requires the APCu PHP extension and the project\'s rollups switch in the console; without APCu nothing is collected or sent.', 'ovos-console'));
		$this->checkboxField('security_events',
			__('Security events', 'ovos-console'),
			__('Report refused actions as security events, apart from errors: failed logins (any door — form, XML-RPC, application passwords, with the username masked), a login succeeding after recent failures (the credential-stuffing success; clean logins are never reported), rejected nonce checks, forbidden REST calls, and sensitive admin changes (user creation and role grants, plugin installs and activations, signup/site-URL/admin-e-mail option changes, file-editor saves, admin application passwords). Informational by default in the console — they feed its attack detection without raising alerts. Rate-limited to 60 per minute.', 'ovos-console'));
		$this->checkboxField('inventory',
			__('Software inventory', 'ovos-console'),
			__('Report the installed plugin/theme list with versions (plus WordPress core and PHP versions) once a day and after installs, updates or (de)activations, so the console can match it against a public vulnerability feed (CVE findings on its SECURITY view). Exactly what is sent per entry: type, directory slug, version, display name, active flag — never paths, options or user data. Inert until the project\'s CVE switch is also enabled in the console.', 'ovos-console'));
		$this->scanFields();
		$this->inputField('release',
			__('Release label', 'ovos-console'), 'text', '',
			__('Optional deploy label (git sha, version), max 64 characters.', 'ovos-console'));
		$this->inputField('environment',
			__('Environment', 'ovos-console'), 'text', '',
			__('Deployment stage sent with every report. Left blank, WordPress\' own environment type (WP_ENVIRONMENT_TYPE) is sent — the console shows non-production values as a badge beside the project name.', 'ovos-console'));
		
		echo '</table>';
		
		echo '<h2>' . esc_html__('Browser errors', 'ovos-console') . '</h2>';
		echo '<table class="form-table" role="presentation">';
		
		$this->checkboxField('js_enabled',
			__('Report JavaScript errors', 'ovos-console'),
			__('Loads the bundled console-client.js on the front end.', 'ovos-console'));
		$this->inputField('js_key',
			__('JS key', 'ovos-console'), 'text', '',
			__('The project\'s public js_key (distinct from the secret API key).', 'ovos-console'));
		$this->checkboxField('js_trace',
			__('Trace correlation', 'ovos-console'),
			__('Send a W3C traceparent header on the page\'s same-origin fetch/XHR calls, so browser and PHP errors of the same request share a trace id in the console. Disable if a firewall or security plugin rejects the extra request header.', 'ovos-console'));
		$this->checkboxField('snapshot',
			__('DOM snapshot', 'ovos-console'),
			__('Upload a masked DOM snapshot with the first error per page load (replay-lite). Input values and scripts are stripped in the browser before upload.', 'ovos-console'));
		$this->checkboxField('snapshot_styles',
			__('Inline styles into snapshots', 'ovos-console'),
			__('Embeds the page\'s CSS so snapshots render styled in the console viewer.', 'ovos-console'));
		$this->checkboxField('js_admin',
			__('Also load in wp-admin and on the login page', 'ovos-console'),
			'');
			
		echo '</table>';
		
		submit_button(__('Save changes', 'ovos-console'));
		
		echo '</form>';
		
		echo '<hr><h2>' . esc_html__('Test', 'ovos-console') . '</h2>';
		
		if($this->sender->isEnabled())
		{
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			
			wp_nonce_field('ovos_console_test');
			
			echo '<input type="hidden" name="action" value="ovos_console_test">';
			
			submit_button(__('Send test error', 'ovos-console'), 'secondary', 'submit', false);
			
			echo '</form>';
		}
		else
		{
			echo '<p>' . esc_html__('Save an enabled configuration (console URL + API key) first, then send a test error.', 'ovos-console') . '</p>';
		}
		
		$this->renderScanSection();
		
		echo '</div>';
	}
	
	public function handleTest(): void
	{
		if(current_user_can('manage_options') === false)
		{
			wp_die(esc_html__('Insufficient permissions.', 'ovos-console'));
		}
		
		check_admin_referer('ovos_console_test');
		
		$status = $this->sender->isEnabled() ? $this->sender->sendTest() : -1;
		
		wp_safe_redirect(add_query_arg(
			'ovos-console-test',
			(string)$status,
			admin_url('options-general.php?page=' . self::PAGE)));
			
		exit;
	}
	
	protected function renderTestNotice(): void
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reads a status flag set by our own redirect; integer-cast, display only
		if(isset($_GET['ovos-console-test']) === false)
		{
			return;
		}
		
		$status = (int)$_GET['ovos-console-test'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		
		[$class, $message] = match(true)
		{
			$status === 202 => ['notice-success',
				__('Test error accepted by the console — it appears in the grid within a second.', 'ovos-console')],
			$status === -1 => ['notice-warning',
				__('Not configured — enable reporting and set the console URL and API key first.', 'ovos-console')],
			$status === 0 => ['notice-error',
				__('Console unreachable — check the URL.', 'ovos-console')],
			$status === 401, $status === 403 => ['notice-error',
				sprintf(
					/* translators: %d: HTTP status code */
					__('Console rejected the key (HTTP %d) — check the project API key.', 'ovos-console'),
					$status)],
			default => ['notice-error',
				sprintf(
					/* translators: %d: HTTP status code */
					__('Unexpected response (HTTP %d).', 'ovos-console'),
					$status)],
		};
		
		echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>'
			. esc_html($message) . '</p></div>';
	}
	
	protected function inputField(
		string $key,
		string $label,
		string $type = 'text',
		string $placeholder = '',
		string $description = '',
	): void
	{
		$locked = $this->config->isConstant($key);
		$value = $type === 'password' ? '' : (string)$this->config->get($key);
		
		if($type === 'password' && $this->config->get($key) !== '')
		{
			$placeholder = __('(unchanged)', 'ovos-console');
		}
		
		echo '<tr><th scope="row"><label for="ovos-console-' . esc_attr($key) . '">'
			. esc_html($label) . '</label></th><td>';
			
		echo '<input type="' . esc_attr($type) . '" class="regular-text"'
			. ' id="ovos-console-' . esc_attr($key) . '"'
			. ' name="' . esc_attr(Config::OPTION . '[' . $key . ']') . '"'
			. ' value="' . esc_attr($value) . '"'
			. ($placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '')
			. ($locked ? ' disabled' : '')
			. ' autocomplete="off">';
			
		$this->fieldNotes($key, $locked, $description);
		
		echo '</td></tr>';
	}
	
	protected function checkboxField(
		string $key,
		string $label,
		string $description,
	): void
	{
		$locked = $this->config->isConstant($key);
		
		echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label>';
		
		if($locked === false)
		{
			// unchecked boxes are absent from the POST — submit an explicit 0
			echo '<input type="hidden"'
				. ' name="' . esc_attr(Config::OPTION . '[' . $key . ']') . '" value="0">';
		}
		
		echo '<input type="checkbox" value="1"'
			. ' name="' . esc_attr(Config::OPTION . '[' . $key . ']') . '"'
			. checked((bool)$this->config->get($key), true, false)
			. ($locked ? ' disabled' : '')
			. '> ' . esc_html($description) . '</label>';
			
		$this->fieldNotes($key, $locked, '');
		
		echo '</td></tr>';
	}
	
	protected function levelField(): void
	{
		$locked = $this->config->isConstant('log_level');
		$current = $this->config->logLevel();
		
		echo '<tr><th scope="row"><label for="ovos-console-log-level">'
			. esc_html__('Log level', 'ovos-console') . '</label></th><td>';
			
		echo '<select id="ovos-console-log-level"'
			. ' name="' . esc_attr(Config::OPTION . '[log_level]') . '"'
			. ($locked ? ' disabled' : '') . '>';
			
		foreach(self::LEVELS as $level => $name)
		{
			echo '<option value="' . esc_attr((string)$level) . '"'
				. selected($current, $level, false) . '>'
				. esc_html($level . ' — ' . $name) . '</option>';
		}
		
		echo '</select>';
		
		$this->fieldNotes('log_level', $locked,
			__('Errors with priority up to and including this level are sent.', 'ovos-console'));
			
		echo '</td></tr>';
	}
	
	protected function fieldNotes(
		string $key,
		bool $locked,
		string $description,
	): void
	{
		if($description !== '')
		{
			echo '<p class="description">' . esc_html($description) . '</p>';
		}
		
		if($locked)
		{
			echo '<p class="description"><code>'
				. esc_html($this->config->constantName($key))
				. '</code> ' . esc_html__('is defined in wp-config.php — the value is locked.', 'ovos-console')
				. '</p>';
		}
	}
	
	/**
	 * The integrity-scan controls in the settings table: the background
	 * switch and its cadence. The Scan now button lives in its own section
	 * below the form and needs neither.
	 */
	protected function scanFields(): void
	{
		$this->checkboxField('scan',
			__('Integrity scan', 'ovos-console'),
			__('Walk this site\'s files in the background for what nobody shipped — PHP under uploads, images that open with a PHP tag, files in the document root WordPress did not ship, .htaccess directives that make images execute, drop-ins without an installed plugin behind them — plus the site\'s hardening posture, and send the findings to the console. Read-only: nothing is ever deleted or changed. Half a second per request after the response went out, one full pass per interval. The Scan now button below works without this switch.', 'ovos-console'));
		
		$locked = $this->config->isConstant('scan_interval');
		$current = $this->config->scanInterval();
		
		echo '<tr><th scope="row"><label for="ovos-console-scan-interval">'
			. esc_html__('Scan interval', 'ovos-console') . '</label></th><td>';
		
		echo '<select id="ovos-console-scan-interval"'
			. ' name="' . esc_attr(Config::OPTION . '[scan_interval]') . '"'
			. ($locked ? ' disabled' : '') . '>';
		
		foreach([1 => __('daily', 'ovos-console'), 7 => __('weekly', 'ovos-console')] as $days => $label)
		{
			echo '<option value="' . esc_attr((string)$days) . '"'
				. selected($current, $days, false) . '>' . esc_html($label) . '</option>';
		}
		
		echo '</select>';
		
		$this->fieldNotes('scan_interval', $locked,
			__('How often the background pass runs.', 'ovos-console'));
		
		echo '</td></tr>';
	}
	
	/**
	 * The outcome of a manual scan round, read back from our own redirect
	 */
	protected function renderScanNotice(): void
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reads an outcome word set by our own redirect; matched against a closed list, display only
		if(isset($_GET['ovos-console-scan']) === false)
		{
			return;
		}
		
		$outcome = sanitize_key((string)$_GET['ovos-console-scan']);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		
		[$class, $message] = match($outcome)
		{
			'done' => ['notice-success', __('Scan finished — the findings are below.', 'ovos-console')],
			'running' => ['notice-info', __('Scan in progress — this page continues it until the pass is complete.', 'ovos-console')],
			'busy' => ['notice-warning', __('Another request is scanning right now — try again in a minute.', 'ovos-console')],
			'failed' => ['notice-error', __('The scan round failed — the position is kept; Continue resumes it.', 'ovos-console')],
			default => ['', ''],
		};
		
		if($message === '')
		{
			return;
		}
		
		echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>'
			. esc_html($message) . '</p></div>';
	}
	
	/**
	 * Below the form: the Scan now button (or the pass in progress), then
	 * the last completed pass — its findings and the site's posture
	 */
	protected function renderScanSection(): void
	{
		echo '<hr><h2>' . esc_html__('Integrity scan', 'ovos-console') . '</h2>';
		echo '<p>' . esc_html__('A read-only walk of this site\'s files for what nobody shipped: PHP under uploads, images that open with a PHP tag, files in the document root WordPress did not ship, hidden PHP, .htaccess and .user.ini directives that make other files execute or send visitors elsewhere, drop-ins and plugin data directories without their plugin — and the hardening posture the advice depends on. Results stay on this page and are sent to the console when one is connected. Nothing is ever deleted or changed; a finding is a place to look, not a verdict.', 'ovos-console') . '</p>';
		
		$state = $this->runner->state();
		
		if($state !== null)
		{
			$this->renderScanProgress($state);
		}
		else
		{
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			
			wp_nonce_field(ScanRunner::ACTION);
			
			echo '<input type="hidden" name="action" value="' . esc_attr(ScanRunner::ACTION) . '">';
			
			submit_button(__('Scan now', 'ovos-console'), 'secondary', 'submit', false);
			
			echo '</form>';
		}
		
		$last = $this->runner->last();
		
		if($last !== null)
		{
			$this->renderScanResult($last);
		}
	}
	
	/**
	 * A pass in progress: where it stands, and the Continue form — which a
	 * manual pass submits by itself, round after round, until it is done
	 */
	protected function renderScanProgress(
		array $state,
	): void
	{
		$progress = Scan::progress($state);
		
		echo '<p><strong>' . esc_html__('Scan in progress', 'ovos-console') . '</strong> — '
			. esc_html(sprintf(
				/* translators: 1: the area being walked, 2: files counted so far, 3: findings so far */
				__('walking %1$s, %2$s files so far, %3$d findings', 'ovos-console'),
				$progress['area'],
				number_format_i18n($progress['files']),
				$progress['findings'])) . '</p>';
		
		echo '<form method="post" id="ovos-console-scan-continue" action="'
			. esc_url(admin_url('admin-post.php')) . '">';
		
		wp_nonce_field(ScanRunner::ACTION);
		
		echo '<input type="hidden" name="action" value="' . esc_attr(ScanRunner::ACTION) . '">';
		
		submit_button(__('Continue', 'ovos-console'), 'secondary', 'submit', false);
		
		echo '</form>';
		
		if(($state['mode'] ?? '') === 'manual')
		{
			wp_print_inline_script_tag('window.setTimeout(function () { document.getElementById("ovos-console-scan-continue").submit(); }, 400);');
		}
	}
	
	/**
	 * The last completed pass: one summary line, the findings table (every
	 * path escaped — it is attacker-authored), the area roots, the posture
	 */
	protected function renderScanResult(
		array $last,
	): void
	{
		$report = (array)$last['report'];
		$scan = (array)($report['scan'] ?? []);
		$counts = (array)($scan['counts'] ?? []);
		$truncated = (array)($scan['truncated'] ?? []);
		$findings = (array)($report['findings'] ?? []);
		$sent = (int)($last['sent'] ?? -1);
		$format = (string)get_option('date_format') . ' ' . (string)get_option('time_format');
		
		$delivery = match(true)
		{
			$sent === 202 => __('accepted by the console', 'ovos-console'),
			$sent === -1 => __('kept here only — no console configured', 'ovos-console'),
			$sent === 0 => __('console unreachable', 'ovos-console'),
			default => sprintf(
				/* translators: %d: HTTP status code */
				__('console answered HTTP %d', 'ovos-console'),
				$sent),
		};
		
		echo '<p>' . esc_html(sprintf(
			/* translators: 1: date and time, 2: manual or background, 3: file count, 4: seconds, 5: delivery outcome */
			__('Last scan: %1$s (%2$s) — %3$s files in %4$s s, %5$s.', 'ovos-console'),
			date_i18n($format, (int)$last['finished']),
			($scan['mode'] ?? '') === 'manual' ? __('manual', 'ovos-console') : __('background', 'ovos-console'),
			number_format_i18n((int)($scan['files'] ?? 0)),
			number_format_i18n(((int)($scan['duration'] ?? 0)) / 1000, 1),
			$delivery)) . '</p>';
		
		if(($scan['complete'] ?? true) === false)
		{
			echo '<p>' . esc_html__('The pass did not complete — the findings below are from the part that was walked.', 'ovos-console') . '</p>';
		}
		
		if($findings === [])
		{
			echo '<p><strong>' . esc_html__('No findings.', 'ovos-console') . '</strong></p>';
		}
		else
		{
			echo '<p><strong>' . esc_html(sprintf(
				/* translators: 1: urgent count, 2: high count, 3: informational count */
				__('%1$d urgent, %2$d high, %3$d informational.', 'ovos-console'),
				(int)($counts[Scan::TIER_URGENT] ?? 0),
				(int)($counts[Scan::TIER_HIGH] ?? 0),
				(int)($counts[Scan::TIER_INFO] ?? 0))) . '</strong>';
			
			$left = (int)($truncated[Scan::TIER_URGENT] ?? 0) + (int)($truncated[Scan::TIER_HIGH] ?? 0)
				+ (int)($truncated[Scan::TIER_INFO] ?? 0);
			
			if($left > 0)
			{
				echo ' ' . esc_html(sprintf(
					/* translators: %d: findings past the cap */
					__('%d more were counted but not listed.', 'ovos-console'),
					$left));
			}
			
			echo '</p>';
			
			echo '<table class="widefat striped"><thead><tr>'
				. '<th>' . esc_html__('Tier', 'ovos-console') . '</th>'
				. '<th>' . esc_html__('File', 'ovos-console') . '</th>'
				. '<th>' . esc_html__('What', 'ovos-console') . '</th>'
				. '<th>' . esc_html__('Detail', 'ovos-console') . '</th>'
				. '<th>' . esc_html__('Modified', 'ovos-console') . '</th>'
				. '</tr></thead><tbody>';
			
			foreach($findings as $finding)
			{
				$finding = (array)$finding;
				$mtime = (int)($finding['mtime'] ?? 0);
				
				echo '<tr>'
					. '<td>' . esc_html($this->tierWord((string)($finding['tier'] ?? ''))) . '</td>'
					. '<td><code>' . esc_html((string)($finding['area'] ?? '') . ':' . (string)($finding['path'] ?? '')
						. (isset($finding['line']) ? ':' . (int)$finding['line'] : '')) . '</code></td>'
					. '<td>' . esc_html($this->detectorLabel((string)($finding['detector'] ?? ''))) . '</td>'
					. '<td>' . esc_html((string)($finding['detail'] ?? '')) . '</td>'
					. '<td>' . esc_html($mtime > 0 ? date_i18n($format, $mtime) : '') . '</td>'
					. '</tr>';
			}
			
			echo '</tbody></table>';
			
			$roots = [];
			
			foreach((array)($report['areas'] ?? []) as $area => $stats)
			{
				$roots[] = (string)$area . ' = ' . (string)(((array)$stats)['root'] ?? '');
			}
			
			echo '<p class="description">' . esc_html(implode(' · ', $roots)) . '</p>';
		}
		
		echo '<h3>' . esc_html__('Posture', 'ovos-console') . '</h3><ul>';
		
		foreach($this->postureLines((array)($report['posture'] ?? [])) as $line)
		{
			echo '<li>' . esc_html($line) . '</li>';
		}
		
		echo '</ul>';
	}
	
	protected function tierWord(
		string $tier,
	): string
	{
		return match($tier)
		{
			Scan::TIER_URGENT => __('urgent', 'ovos-console'),
			Scan::TIER_HIGH => __('high', 'ovos-console'),
			default => __('info', 'ovos-console'),
		};
	}
	
	protected function detectorLabel(
		string $detector,
	): string
	{
		return match($detector)
		{
			'uploads_php' => __('PHP file under uploads', 'ovos-console'),
			'polyglot' => __('PHP code in a media file', 'ovos-console'),
			'root_php' => __('PHP file in the document root that WordPress did not ship', 'ovos-console'),
			'root_php_owned' => __('PHP file in the document root (owned by a plugin)', 'ovos-console'),
			'hidden_php' => __('PHP file in a hidden path', 'ovos-console'),
			'content_php' => __('PHP file in wp-content outside any plugin or theme', 'ovos-console'),
			'mu_plugin' => __('must-use plugin (loads on every request)', 'ovos-console'),
			'dropin' => __('drop-in', 'ovos-console'),
			'dropin_orphan' => __('drop-in without an installed owner or a vendor header', 'ovos-console'),
			'writer_dir' => __('plugin data directory (not scanned)', 'ovos-console'),
			'writer_dir_orphan' => __('plugin data directory whose plugin is not installed', 'ovos-console'),
			'directive_prepend' => __('auto_prepend/append directive to a file nobody owns', 'ovos-console'),
			'directive_owned' => __('auto_prepend/append directive (owned by a plugin)', 'ovos-console'),
			'handler_php_extension' => __('PHP handler mapped to another extension', 'ovos-console'),
			'set_handler_php' => __('SetHandler to PHP', 'ovos-console'),
			'engine_on_uploads' => __('PHP engine switched on under uploads', 'ovos-console'),
			'cgi_uploads' => __('CGI execution under uploads', 'ovos-console'),
			'redirect_external' => __('redirect to another host', 'ovos-console'),
			'ini_prepend' => __('auto_prepend/append file live in php.ini', 'ovos-console'),
			'ini_prepend_owned' => __('auto_prepend/append file live in php.ini (owned by a plugin)', 'ovos-console'),
			'core_modified' => __('core file differs from what wordpress.org shipped', 'ovos-console'),
			'core_foreign' => __('file under core that wordpress.org never shipped', 'ovos-console'),
			'plugin_modified' => __('plugin file differs from what wordpress.org shipped', 'ovos-console'),
			'plugin_foreign' => __('file in a wp.org plugin that its release never shipped', 'ovos-console'),
			'admin_account' => __('administrator account (registered, sessions)', 'ovos-console'),
			'admin_app_password' => __('application password on an administrator', 'ovos-console'),
			'active_plugin_missing' => __('active plugin whose file is not on disk', 'ovos-console'),
			'cron_orphan' => __('scheduled hook no plugin listens to', 'ovos-console'),
			'cron_suspicious' => __('scheduled hook no plugin listens to, named like a payload', 'ovos-console'),
			'uninstall_orphan' => __('uninstall callable for a plugin that is not installed', 'ovos-console'),
			'option_code' => __('option value carrying code markers', 'ovos-console'),
			'content_script' => __('script from a foreign host in published content', 'ovos-console'),
			'content_iframe' => __('iframe from a foreign host in published content', 'ovos-console'),
			'content_obfuscated' => __('obfuscation idiom in published content', 'ovos-console'),
			'option_drift' => __('stored site URL disagrees with its constant', 'ovos-console'),
			'registration_role' => __('registration open into a role above subscriber', 'ovos-console'),
			'dir_changed' => __('directory changed after its newest file (something removed)', 'ovos-console'),
			'owner_anomaly' => __('file owned by another uid than its siblings', 'ovos-console'),
			'symlink_outside' => __('symlink leaving the site', 'ovos-console'),
			default => $detector,
		};
	}
	
	/**
	 * The posture block as plain lines — what the removal advice leans on
	 *
	 * @return string[]
	 */
	protected function postureLines(
		array $posture,
	): array
	{
		if($posture === [])
		{
			return [__('not recorded', 'ovos-console')];
		}
		
		$yes = __('yes', 'ovos-console');
		$no = __('no', 'ovos-console');
		$unknown = __('unknown', 'ovos-console');
		$word = static fn(mixed $value): string => $value === null ? $unknown : ($value ? $yes : $no);
		$ini = (array)($posture['ini'] ?? []);
		$vcs = (array)($posture['vcs_exposed'] ?? []);
		
		return [
			__('Web server', 'ovos-console') . ': ' . (string)($posture['server'] ?? $unknown),
			__('File editor disabled (DISALLOW_FILE_EDIT)', 'ovos-console') . ': ' . $word($posture['file_edit_disabled'] ?? null),
			__('File modifications disabled (DISALLOW_FILE_MODS)', 'ovos-console') . ': ' . $word($posture['file_mods_disabled'] ?? null),
			__('Debug output displayed', 'ovos-console') . ': ' . $word($posture['debug_display'] ?? null),
			__('PHP execution denied under uploads by .htaccess', 'ovos-console') . ': ' . $word($posture['uploads_php_denied'] ?? null),
			__('Uploads directory writable by everyone', 'ovos-console') . ': ' . $word($posture['uploads_world_writable'] ?? null),
			__('wp-config.php readable by everyone', 'ovos-console') . ': ' . $word($posture['config_world_readable'] ?? null),
			__('XML-RPC enabled', 'ovos-console') . ': ' . $word($posture['xmlrpc'] ?? null),
			__('Registration open', 'ovos-console') . ': ' . $word($posture['users_can_register'] ?? null)
				. (($posture['users_can_register'] ?? false) ? ' (' . (string)($posture['default_role'] ?? '') . ')' : ''),
			__('Version control in the document root', 'ovos-console') . ': ' . ($vcs === [] ? $no : implode(', ', $vcs)),
			__('readme.html present', 'ovos-console') . ': ' . $word($posture['readme_html'] ?? null),
			'auto_prepend_file: ' . ((string)($ini['auto_prepend_file'] ?? '') !== '' ? (string)$ini['auto_prepend_file'] : $no),
			'disable_functions: ' . ((string)($ini['disable_functions'] ?? '') !== '' ? (string)$ini['disable_functions'] : $no),
			'open_basedir: ' . ((string)($ini['open_basedir'] ?? '') !== '' ? $yes : $no),
			'OPcache: ' . $word($ini['opcache'] ?? null),
		];
	}
}
