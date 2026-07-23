=== ovos console ===
Contributors: ovos
Tags: error monitoring, error reporting, javascript errors, logging, debugging
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your site to an ovos/console error-monitoring instance — PHP and JavaScript errors, grouped into issues, alerted, resolved.

== Description ==

This plugin reports errors from your WordPress site to a self-hosted [ovos/console](https://www.ovos.at) instance:

* **PHP errors** — warnings, notices and fatals (uncaught exceptions included) are batched and posted once per request from the shutdown handler, after the response went out. Reporting never blocks or breaks the site: every failure is swallowed, the HTTP call has a hard 1 s timeout.
* **JavaScript errors** — the bundled browser client captures window errors, unhandled rejections and failed fetch/XHR calls, with breadcrumbs and an optional masked DOM snapshot (replay-lite). Same-origin calls carry a W3C traceparent header, so a failed browser request and the PHP error behind it share one trace id in the console.
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

= 0.3.5 =
* Wider PII redaction, server- and browser-side: e-mail addresses found in any request value, URL (including the referer) or WP-CLI argument are now masked (domain kept, e.g. j***@example.com); username-named fields (login, user_login, user/username params) are anonymized to their first character; `pwd` was added to the secret-field pattern. Extra context passed to `captureException()`/`captureMessage()` and the bundled browser client's own extra data are scrubbed the same way before they leave the site.
* Bundled console-client.js: `blob:`/`data:` script urls are no longer treated as first-party evidence for JavaScript error reporting — such urls can be minted by browser extensions or injected third-party tags on your page, not just your own code, so they no longer cause otherwise-foreign errors to be reported.

= 0.3.4 =
* Bundled console-client.js refreshed (lockstep with the console): browser JavaScript error reporting is now an allowlist — only errors attributable to your own code (same-origin scripts, their workers, and inline scripts in server-rendered markup) are reported. Errors from browser extensions, in-app-browser native bridges (Facebook, Google apps) and cross-origin/CDN third parties — which the site cannot fix — are dropped at the source, so no future injected-noise variant needs a client update. No settings changes.

= 0.3.3 =
* Bundled console-client.js refreshed (lockstep with the console): errors thrown by scripts in-app browsers inject (Facebook's iabjs:// "Java object is gone", gsa://, webkit-masked-url://) and by more extension scheme variants are no longer reported. No settings changes.

= 0.3.2 =
* Bundled console-client.js refreshed (lockstep with the console): internal cleanup, crypto-quality page/snapshot ids where the browser supports it. No settings or behavior changes.

= 0.3.1 =
* Bundled console-client.js updated in lockstep with the console (adds an OTLP collector export capability; unused by the plugin — no settings changed).

= 0.3.0 =
* Browser-side trace correlation: the bundled client now sends a W3C traceparent header on the page's same-origin fetch/XHR calls, so a failed browser request and the PHP error behind it share one trace id in the console (completes the server half shipped in 0.2.0). New "Trace correlation" setting (on by default, `OVOS_CONSOLE_JS_TRACE` constant) to disable it if a firewall rejects the header.
* Bundled console-client.js updated: an app-set traceparent (own OpenTelemetry SDK) is honored, fetch/XHR breadcrumbs carry the trace id of each call.

= 0.2.1 =
* Lowered the PHP requirement from 8.3 to 8.1 — no functional changes.

= 0.2.0 =
* OpenTelemetry-compatible trace correlation: every report carries a per-request trace id — the trace id of an inbound W3C traceparent header when present (OTEL SDKs, service meshes), a generated id otherwise — indexed by the console as trace_id.

= 0.1.0 =
* Initial release: PHP sender (error handler + shutdown fatals), bundled browser client, settings page with wp-config constant overrides, test error button.
