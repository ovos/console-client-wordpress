# ovos console — WordPress plugin

Reports errors from a WordPress site to a self-hosted [ovos/console](https://www.ovos.at) error-monitoring instance:

- **PHP errors** — warnings, notices and fatals (uncaught exceptions included), batched into a single POST from the shutdown handler after the response went out. Fire-and-forget: every failure is swallowed, the HTTP call has a hard 1 s timeout — reporting can never break or noticeably slow the site.
- **JavaScript errors** — the bundled browser client captures window errors, unhandled rejections and failed fetch/XHR calls, with breadcrumbs and an optional masked DOM snapshot (replay-lite).
- **Context** — request variables (redacted before sending), logged-in user id, WordPress version, active theme, and source attribution: each error is tagged with the plugin or theme its file lives in.

## Requirements

- WordPress 6.0+
- PHP 8.3+ (the `Requires PHP` header prevents activation on older hosts)
- an ovos/console instance reachable from this site

## Installation

### From a release zip

1. Download `ovos-console.zip` from the [releases page](../../releases).
2. In wp-admin go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, install and activate.

### From git

Clone into `wp-content/plugins` — the directory name must be `ovos-console`:

```
cd wp-content/plugins
git clone git@github.com:ovos/console-client-wordpress.git ovos-console
```

Activate the plugin in wp-admin.

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
| Release label | `OVOS_CONSOLE_RELEASE` | — | optional deploy label (git sha, version), max 64 chars |
| Report JS errors | `OVOS_CONSOLE_JS_ENABLED` | `true` | loads the bundled browser client on the front end |
| JS key | `OVOS_CONSOLE_JS_KEY` | — | the project's public js_key (browser errors) |
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
