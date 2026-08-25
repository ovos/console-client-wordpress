<?php
declare(strict_types=1);

namespace OvosConsole;

use function array_keys;
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
			'release' => mb_substr(sanitize_text_field((string)($input['release'] ?? '')), 0, 64),
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
			__('Report refused actions as security events, apart from errors: failed logins (any door — form, XML-RPC, application passwords, with the username masked), rejected nonce checks, forbidden REST calls, and sensitive admin changes (role grants, plugin installs and activations, signup/site-URL/admin-e-mail option changes). Informational by default in the console — they feed its attack detection without raising alerts. Rate-limited to 60 per minute.', 'ovos-console'));
		$this->inputField('release',
			__('Release label', 'ovos-console'), 'text', '',
			__('Optional deploy label (git sha, version), max 64 characters.', 'ovos-console'));
		
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
}
