=== ovos console ===
Contributors: ovos
Tags: error monitoring, error reporting, javascript errors, logging, debugging
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your site to an ovos/console error-monitoring instance — PHP and JavaScript errors, grouped into issues, alerted, resolved.

== Description ==

This plugin reports errors from your WordPress site to a self-hosted [ovos/console](https://www.ovos.at) instance:

* **PHP errors** — warnings, notices and fatals (uncaught exceptions included) are batched and posted once per request from the shutdown handler, after the response went out. Reporting never blocks or breaks the site: every failure is swallowed, the HTTP call has a hard 1 s timeout.
* **JavaScript errors** — the bundled browser client captures window errors, unhandled rejections and failed fetch/XHR calls, with breadcrumbs and an optional masked DOM snapshot (replay-lite).
* **Context** — request variables (redacted before sending), logged-in user id, WordPress version, active theme, and source attribution: each error is tagged with the plugin or theme its file lives in.

Configuration lives under Settings → ovos console, or in wp-config.php via `OVOS_CONSOLE_*` constants (which lock the corresponding UI field — handy for deploy-time configuration):

`define('OVOS_CONSOLE_ENABLED', true);`
`define('OVOS_CONSOLE_URL', 'https://console.example');`
`define('OVOS_CONSOLE_API_KEY', '...');`
`define('OVOS_CONSOLE_JS_KEY', '...');`

For a console instance behind a self-signed certificate (intranet setups), TLS verification of the ingest call can be disabled:

`add_filter('ovos_console_sslverify', '__return_false');`

Manual captures from theme or plugin code:

`ovos_console()->captureException($e, ['orderId' => 7]);`
`ovos_console()->captureMessage('checkout step skipped', 4);`

== Installation ==

1. Install and activate the plugin.
2. In your console instance, create a project; note its secret API key and public JS key, and allowlist this site's origin for browser errors.
3. Enter the console URL and keys under Settings → ovos console, enable reporting, and use "Send test error" to verify the connection.

== Changelog ==

= 0.1.0 =
* Initial release: PHP sender (error handler + shutdown fatals), bundled browser client, settings page with wp-config constant overrides, test error button.
