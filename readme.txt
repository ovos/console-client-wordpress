=== ovos console ===
Contributors: ovos
Tags: error monitoring, error reporting, javascript errors, logging, debugging
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your site to an ovos/console error-monitoring instance — PHP and JavaScript errors, grouped into issues, alerted, resolved.

== Description ==

This plugin reports errors from your WordPress site to a self-hosted [ovos console](https://ovos.github.io/console/) instance — one live dashboard for the errors of every site and service you run, grouped into issues, alerted and resolved, on infrastructure you control.

See it working first: the [live demo](https://console-demo.ovos.at/) is a public instance filled with synthetic errors, no login required.

What the plugin sends:

* **PHP errors** — warnings, notices and fatals (uncaught exceptions included) are batched and posted once per request from the shutdown handler, after the response went out. Reporting never blocks or breaks the site: every failure is swallowed, the HTTP call has a hard 1 s timeout.
* **JavaScript errors** — the bundled browser client captures window errors, unhandled rejections and failed fetch/XHR calls, with breadcrumbs and an optional masked DOM snapshot (replay-lite). Same-origin calls carry a W3C traceparent header, so a failed browser request and the PHP error behind it share one trace id in the console.
* **Context** — request variables (redacted before sending), logged-in user id, WordPress version, active theme, and source attribution: each error is tagged with the plugin or theme its file lives in.
* **Traffic rollups (opt-in)** — anonymous per-minute request counters: totals split by response status, HTTP method, the page type WordPress resolved (front page, post type, archive, search, login, admin, REST) and logged-in state. Never URLs, IPs or visitor data. They give the console a denominator, so error and scanner-probe counts read as rates against real traffic — a request answered 404 counts only as "matched nothing", which is exactly the probe signal. Requires the APCu PHP extension (counters accumulate in shared memory, one small POST per minute of traffic; without APCu the feature is silently inert). Enable it under Settings → ovos console (or `OVOS_CONSOLE_ROLLUPS`) *and* on the console project — either switch off keeps it inert. Requests served by a page-cache plugin before WordPress boots are not counted.
* **Security events (opt-in)** — what WordPress *refused*, beside what broke: failed logins from any door (wp-login form, XML-RPC, application passwords — the username is masked to its first character), rejected nonce checks (the CSRF signal), REST calls answered 401/403 (permission probing), and sensitive admin changes worth an audit trail — role grants, plugin installs and activations, changes to the signup, site-URL and admin-e-mail options. They arrive in the console as informational `security` events, grouped apart from errors and accepted independently of the project's severity threshold; they raise no alerts by default and feed the console's attack detection. Capped at 60 reports per minute so an attack cannot flood its own report channel. Enable it under Settings → ovos console (or `OVOS_CONSOLE_SECURITY_EVENTS`).

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

The plugin is distributed as a release zip from its [GitHub repository](https://github.com/ovos/console-client-wordpress) and updates itself from there afterwards.

1. Download `ovos-console.zip` from the [latest release](https://github.com/ovos/console-client-wordpress/releases/latest) and install it under Plugins → Add New Plugin → Upload Plugin, or install it with WP-CLI:

`wp plugin install https://github.com/ovos/console-client-wordpress/releases/latest/download/ovos-console.zip --activate`

2. Activate the plugin.
3. In your console instance, create a project; note its secret API key and public JS key, and allowlist this site's origin for browser errors.
4. Enter the console URL and keys under Settings → ovos console, enable reporting, and use "Send test error" to verify the connection.

From 0.4.5 on the plugin keeps itself current: its `Update URI` header points WordPress core's own update flow at this repository's GitHub releases, so new versions appear under Dashboard → Updates and install like any directory plugin — including unattended, via the plugin's "Enable auto-updates" toggle (`wp plugin auto-updates enable ovos-console`). No updater plugin and no license key involved; a failed check simply means "no update visible right now".

== Changelog ==

= 0.4.8 =
* New: optional security events. When enabled (Settings → ovos console → "Security events", off by default, lockable via `OVOS_CONSOLE_SECURITY_EVENTS`), the plugin reports what WordPress refused, beside what broke: failed logins from any door (wp-login form, XML-RPC, application passwords), rejected nonce checks, REST calls answered 401/403, and sensitive admin changes (role grants, plugin installs and activations, changes to the signup/site-URL/admin-e-mail options). Usernames are masked to their first character and e-mail addresses in messages are masked with the domain kept. The console accepts these as informational `security` events independent of the project's severity threshold — grouped apart from errors, no alerts by default, feeding its attack detection. Rate-limited to 60 reports per minute, so a credential-stuffing run cannot turn the reporter into the flood it surfaces.

= 0.4.7 =
* New: optional traffic rollups. When enabled (Settings → ovos console → "Traffic rollups", off by default, lockable via `OVOS_CONSOLE_ROLLUPS`), the plugin sends anonymous per-minute request counters to the console — totals split by response status, HTTP method, the page type WordPress resolved (front page, singular/{post_type}, archive, search, login, admin, REST…) and logged-in state. Never URLs, IPs or visitor data: a request answered 404 counts only as "matched nothing", which is exactly the scanner-probe signal the console's attack detection reads as a rate instead of a raw count. Requires the APCu PHP extension (counters accumulate in shared memory and ship once per minute as a single request — without APCu the feature is silently inert, never a slowdown) and the project's rollups switch in the console. Requests served entirely by a page-cache plugin before WordPress boots are not counted.

= 0.4.6 =
* Fix: settings save on the first try. On a fresh install the very first "Save changes" dropped every checkbox back to unchecked — only the URL, the keys and the log level stuck — and the same boxes had to be ticked and saved a second time to hold. When the option row does not exist yet, WordPress core sanitizes the submission twice (update_option() falls through to add_option(), which sanitizes again — core ticket #21989), and the second pass fed the already-sanitized booleans back into a strict comparison built for the form's '1'/'0' strings, which quietly turned every true into false. The checkbox parsing now accepts both the raw form value and its sanitized boolean, so the double pass is harmless.

= 0.4.5 =
* New: the plugin updates itself. The header now declares this repository as its `Update URI`, and WordPress core's own update flow (WP 5.8+) asks the plugin for its latest GitHub release — new versions appear under Dashboard → Updates and install like any directory plugin, straight from the release zip. The check is fire-and-forget (any failure just means "no update visible right now") and the GitHub answer is cached for twelve hours. Sites running 0.4.4 or older still need one last manual install of this version; every version after it arrives through the updater.
* Change: webpack chunk-load failures (`ChunkLoadError`) are no longer reported as errors by default. A lazy chunk that times out on a visitor's stalled connection is a failed download, not broken code — one Elementor text-editor chunk alone produced 44 reports in a single evening — but webpack repackages the failure as a rejection whose same-origin runtime frames walked it past the first-party filter. The bundled client now routes it through the same policy as every other resource load failure: a breadcrumb on the trail (so the TypeErrors that follow still show their cause; the timeout flavor fires no error event, so the resource handler never saw it), and a full report only where `reportResourceErrors` opted broken script loads in — a `(missing:)` chunk right after a deploy is still the loud broken-deploy signal that switch exists for. Covers the CSS-chunk variant (`Loading CSS chunk … failed`) too.

= 0.4.4 =
* Fix: on hosts whose curl lacks the threaded DNS resolver, every error report was lost. Such builds time sub-second timeouts via SIGALRM, which cannot do sub-second at all — libcurl refused the sender's 300 ms connect bound outright ("remaining timeout of 300 too small to resolve via SIGALRM method") before even resolving the console's hostname, and the fire-and-forget contract swallowed the failure, so nothing ever arrived and nothing ever said why. The ingest call now sets CURLOPT_NOSIGNAL, which times the connect by polling instead; hosts with the threaded resolver behave exactly as before. Found on a shared-hosting box where the identical call succeeds in 36 ms once allowed to run.
* Bundled console-client.js: exposes a `stubAware: true` marker on `window.ovosConsole` so a loader that must keep working against older, pre-drain consoles (e.g. ovos-play) can detect stub-drain support onload and fall back to init()ing directly when it's absent.

= 0.4.3 =
* New: errors thrown before the browser client finishes loading are no longer lost. The client file loads async (a slow console must never block the page), but its handlers only attached once it arrived — anything thrown in that window was gone, and that window sits exactly where the interesting errors live: first visits, cold caches, slow connections, broken deploys. The bootstrap now emits a tiny inline stub first, which buffers error and unhandledrejection events (resource load failures included) plus the init options; the refreshed bundled client drains the stub on arrival and replays the buffer through its normal filtering, so nothing is reported twice and nothing extra gets through. Manual captures made against the stub are replayed too.
* Change: the client `<script>` tag is printed as a literal tag instead of being injected with `createElement` — the browser's preload scanner only discovers literal tags, so the fetch now starts while the HTML is still being parsed.
* Bundled console-client.js refreshed (lockstep with the console): adds the stub drain; pages that include it synchronously behave exactly as before.
* Fix: hashed asset filenames are no longer redacted. A JavaScript error names the file it came from, and every build tool names its output after a content hash — `main.<md5>.js`, `index-DkL9mQxZ8vB2nR4tY7wA.js`. Those look exactly like a generated token, so the redaction rule replaced them: reports arrived saying the error came from `/[redacted]`, which is the one thing on the report you cannot work without. Nothing was protected by it — the browser had just fetched the file over a plain, uncredentialed request. A segment ending in a script, style, font, image or media extension is now kept. One-time credentials in a URL path (`/reset/<token>`, `/invite/<token>`) are bare segments and are still redacted, as are token-shaped names ending in `.pdf` or `.zip`.

= 0.4.2 =
* Security: `console.error('...', obj)` breadcrumbs are scrubbed properly. The object was serialised to JSON BEFORE the scrubber saw it, and the scrubber judges by field NAME — a JSON string has no names left to judge — so logging a failed response body recorded it whole, access token included. The server-side backstop is name-based too and never re-reads a breadcrumb, so nothing downstream caught it either.
* Fix: error reports that do not fit one request are no longer lost. When a batch exceeded the send limit the surplus reports were dropped, and because they had already been counted against the per-page cap they could never be sent again — a later re-throw of the same error only bumped a counter nobody would receive. They are now carried over to the next batch.
* Fix: loading the client twice (a theme or another plugin calling it as well) no longer records every navigation twice, which halved the useful length of the breadcrumb trail.
* Fix: an over-sized report is sent instead of being thrown away. The browser refuses a "keepalive" request over ~64 KB outright rather than sending it slowly, and that flag was set even on the fallback path that is only reached BECAUSE the payload was too big — so the report was lost on a page that was still open.

= 0.4.1 =
* Fix: URL redaction no longer eats readable page paths. The bundled browser client shipped a rule that redacted ANY path segment of 24+ characters, which is also what a long slug looks like — so a page like /de/anmeldung-und-registrierung/ was reported as /de/[redacted]/ and the console's URI column stopped saying anything. A segment is now judged by its structure (a JWT, a uuid, a long hex string or one long run of mixed case with digits) rather than by its length alone.
* Security: WordPress password-reset links are redacted. The reset token travels as wp-login.php?action=rp&key=..., and `key` matched none of the secret-field patterns (which look for api_key), so an error or a 404 on a reset link sent a live token to the console. `key`, `auth`, `code`, `sig` and `signature` are now dropped as query parameters. The user name beside it was already masked.
* Security: token-shaped path segments are redacted server-side too. Single-use credentials travel in paths and are followed over GET — /reset/<token>, /invite/<token> — and only the browser client used to catch those; a 404 or a PHP error on such a URL sent the token.
* Fix: `key`, `auth`, `code`, `sig` and `signature` were only being dropped as query parameters on the server; the bundled browser client's own pattern hadn't been taught the same names, so a JS error report could still carry them. The client now drops them too, matched by exact parameter name (not substring) so `?design=`, `?assign=` or `?barcode=` aren't caught by mistake.
* Fix: the placeholder for a dropped value is now `[redacted]` on both sides — the server was writing `[removed]` while the browser client wrote `[redacted]`, so the same kind of drop read differently depending on which side did it.
* Security: browser JavaScript error reports now scrub their source file/url instead of only truncating it. For an inline `<script>`, `window.onerror` hands back the document's own URL as the error's file — reset token or query string included — and that was passed through truncated but unredacted.

= 0.4.0 =
* New: optional 404 reporting. When enabled (Settings → ovos console → "Report 404s", off by default), front-end not-found requests are reported to the console as access events — surfacing scanner probes and broken links, grouped apart from real errors and never turned into issues. Rate-limited so a scan cannot flood, and static-asset 404s (images, styles, scripts) are ignored. Lockable via an `OVOS_CONSOLE_REPORT_404` constant. Requires a console instance that understands the 404 error type.

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
