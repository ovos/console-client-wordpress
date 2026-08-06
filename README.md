# ovos console — WordPress plugin

Reports errors from a WordPress site to a self-hosted [ovos console](https://ovos.github.io/console/) error-monitoring instance:

- **PHP errors** — warnings, notices and fatals (uncaught exceptions included), batched into a single POST from the shutdown handler after the response went out. Fire-and-forget: every failure is swallowed, the HTTP call has a hard 1 s timeout — reporting can never break or noticeably slow the site.
- **JavaScript errors** — the bundled browser client captures window errors, unhandled rejections and failed fetch/XHR calls, with breadcrumbs and an optional masked DOM snapshot (replay-lite).
- **Context** — request variables (redacted before sending), logged-in user id, WordPress version, active theme, and source attribution: each error is tagged with the plugin or theme its file lives in.

## The console

[![The ovos console errors grid — live rows with project, type, priority, message and occurrence counts](https://ovos.github.io/console/assets/errors.png)](https://console-demo.ovos.at/)

**[Try the live demo →](https://console-demo.ovos.at/)** — a public instance filled with synthetic errors. No login, no sign-up: browse the grid, expand a row for the full backtrace and request context, filter the issues, look at the monitors. Changes are disabled, triage (check, star, resolve) is not — press <kbd>?</kbd> for the keyboard map.

One console for everything you run, on infrastructure you control:

- **Live** — errors from PHP, browser JavaScript, WordPress, Node.js and OpenTelemetry services land the moment they happen; an error storm is ingested through Redis Streams without slowing the app that reported it.
- **Issues, not noise** — the same error a thousand times is one row with a count, fingerprinted into an issue with an open → resolved → regressed lifecycle.
- **Trace correlation** — a failed browser request and the PHP error behind it share one trace id, so a broken request lines up across services in a click.
- **Uptime** — dead-man's-switch heartbeats for cron jobs and health-URL probes, so you hear about the job that stopped running before your users do.
- **Alerting** — priority-gated, throttled email and chat notifications, per project.
- **Ask your AI** — point Claude or any MCP client at the console and ask about your errors, or hit `◇ AI EXPLAIN` on any row for a plain-English root-cause read.

Self-hosted, so stack traces and user data never leave your infrastructure — and no per-event bill that grows with your traffic. More on the [product page](https://ovos.github.io/console/); if you would rather not run it yourself, [we host it for you](https://ovos.at/en/contact/) from Vienna.

## Requirements

- WordPress 6.0+
- PHP 8.1+ (the `Requires PHP` header prevents activation on older hosts)
- an ovos console instance reachable from this site

## Installation

The plugin is not on wordpress.org — it installs from the release zip on this
repository and then keeps itself up to date (see [Automatic
updates](#automatic-updates) below).

### From wp-admin

1. Download [`ovos-console.zip`](https://github.com/ovos/console-client-wordpress/releases/latest/download/ovos-console.zip) — that link always resolves to the newest release; the [releases page](../../releases) has the older ones and the changelog.
2. Go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, install and activate.

### With WP-CLI

```sh
wp plugin install https://github.com/ovos/console-client-wordpress/releases/latest/download/ovos-console.zip --activate
```

The zip carries an `ovos-console/` prefix, so it installs under that directory
name and later updates swap it cleanly. Configure it in the same breath —
`wp config set` writes the constants into `wp-config.php`, which take
precedence over the settings page and lock the corresponding fields:

```sh
wp config set OVOS_CONSOLE_ENABLED true --raw
wp config set OVOS_CONSOLE_URL https://console.example
wp config set OVOS_CONSOLE_API_KEY 'the project api_key'
wp config set OVOS_CONSOLE_JS_KEY 'the project js_key'
```

Updating, and turning on unattended updates:

```sh
wp plugin update ovos-console
wp plugin auto-updates enable ovos-console
```

To install one specific version — a rollback, or pinning a fleet:

```sh
wp plugin install https://github.com/ovos/console-client-wordpress/releases/download/v0.4.6/ovos-console.zip --force
```

### From git

Clone into `wp-content/plugins` — the directory name must be `ovos-console`:

```sh
cd wp-content/plugins
git clone git@github.com:ovos/console-client-wordpress.git ovos-console
```

Activate the plugin in wp-admin. Update it with `git pull`, and leave
auto-updates off for this copy: applying an update from wp-admin replaces the
whole directory with the release zip, `.git` included.

### Automatic updates

From 0.4.5 on the plugin keeps itself current, with no updater plugin, license
key or update server involved. Its header declares this repository as its
`Update URI`, so WordPress core's own update flow (5.8+) asks the plugin for
its latest GitHub release: new versions appear under **Dashboard → Updates**
and install like any directory plugin, straight from the release zip.

- **One-click**, from **Dashboard → Updates** or the Plugins screen, or `wp plugin update ovos-console`.
- **Unattended** — flip **Enable auto-updates** in the Plugins list (`wp plugin auto-updates enable ovos-console`) and core's twice-daily cron installs new versions on its own.
- The check is fire-and-forget: an offline host, a rate-limited GitHub API or a missing asset just means "no update visible right now", never an error on your dashboard.
- A successful answer is cached for twelve hours. **Check again** on the updates screen bypasses core's own cache; to also drop the plugin's, `wp transient delete ovos_console_latest_release`.

Sites still on 0.4.4 or older need one last manual install of a newer version;
everything after that arrives through the updater.

### Connect it to the console

1. In your console instance, create a project (PROJECTS tab) and note its secret **api_key** and public **js_key**.
2. For browser errors, enable JS errors on the project and allowlist this site's exact origin (`scheme://host[:port]`, e.g. `https://www.example.com`) in the project's JS origins.
3. In wp-admin go to **Settings → ovos console**, enter the console URL and both keys, tick **Enabled**, and save.
4. Click **Send test error** — the settings page reports the console's response, and the error appears in the console grid within a second.

## Configuration

Every value lives under **Settings → ovos console** and can alternatively be set as a constant in `wp-config.php` — a defined constant wins and locks the corresponding field in the UI (handy for deploy-time configuration):

| Setting | Constant | Default | |
|---|---|---|---|
| Enabled | `OVOS_CONSOLE_ENABLED` | `false` | master switch for both PHP and browser reporting |
| Console URL | `OVOS_CONSOLE_URL` | — | instance base URL, e.g. `https://console.example` |
| API key | `OVOS_CONSOLE_API_KEY` | — | the project's secret api_key (PHP errors) |
| Log level | `OVOS_CONSOLE_LOG_LEVEL` | `4` | send errors with syslog priority ≤ this (0 emergency … 7 debug) |
| Report 404s | `OVOS_CONSOLE_REPORT_404` | `false` | front-end not-found requests as access events — rate-limited, static assets ignored, never turned into issues |
| Release label | `OVOS_CONSOLE_RELEASE` | — | optional deploy label (git sha, version), max 64 chars |
| Report JS errors | `OVOS_CONSOLE_JS_ENABLED` | `true` | loads the bundled browser client on the front end |
| JS key | `OVOS_CONSOLE_JS_KEY` | — | the project's public js_key (browser errors) |
| Trace correlation | `OVOS_CONSOLE_JS_TRACE` | `true` | W3C traceparent header on the page's same-origin fetch/XHR calls |
| DOM snapshot | `OVOS_CONSOLE_SNAPSHOT` | `false` | masked DOM snapshot with the first error per page load |
| Inline snapshot styles | `OVOS_CONSOLE_SNAPSHOT_STYLES` | `false` | embed the page's CSS so snapshots render styled |
| Load in wp-admin | `OVOS_CONSOLE_JS_ADMIN` | `false` | also report browser errors from wp-admin and the login page |

Example `wp-config.php` block:

```php
define('OVOS_CONSOLE_ENABLED', true);
define('OVOS_CONSOLE_URL', 'https://console.example');
define('OVOS_CONSOLE_API_KEY', '...');
define('OVOS_CONSOLE_JS_KEY', '...');
define('OVOS_CONSOLE_RELEASE', '2026.07.13');
```

### Self-signed console certificate

TLS verification of the ingest call is on by default. For a console behind a self-signed certificate (intranet instances), disable it from an mu-plugin or your theme:

```php
add_filter('ovos_console_sslverify', '__return_false');
```

## Manual captures

```php
ovos_console()->captureException($e, ['orderId' => 7]);
ovos_console()->captureMessage('checkout step skipped', 4); // priority 4 = warning
```

Both are safe to call unconditionally — when the plugin is disabled or unconfigured the calls are no-ops.

## Development notes

- The repository root **is** the plugin directory — for local development, symlink/junction it into `wp-content/plugins/ovos-console`.
- `assets/console-client.js` is a bundled copy of the console's browser client (intentionally ES5 — do not modernize); it is synced from the console repository on client releases, never edited here.
- `readme.txt` is the wordpress.org-format readme; this file is for GitHub.
- Releasing: push a `v*` tag whose version matches the plugin header and readme.txt stable tag (e.g. `git tag v0.1.0 && git push origin v0.1.0`) — the release workflow verifies the versions, builds the zip via `git archive` and publishes a GitHub release with `ovos-console.zip` attached.

## License

[GPL-2.0-or-later](LICENSE)
