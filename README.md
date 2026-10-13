# Sitetus WordPress plugin

The read-only collector of the [Sitetus](https://sitetus.com) monitoring panel, packaged as a WordPress plugin. User-facing description and directory listing: `readme.txt`.

## How it fits together

- `includes/collector/` is the **vendored core** of [`sitetus/collector`](https://github.com/sitetus/collector-php) — same files, same namespaces, copied by `php bin/sync-core.php` from a sibling checkout (never edited here). `CORE_VERSION` records the copied version.
- `src/` is the WordPress glue: `Plugin` (hooks), `Settings` (the pairing in `wp_options`, plus the last-pull record), `PairingCode` (decode + validate), `NonceDirectory` (replay store under `wp-content/sitetus`, web-denied; `wp-content/uploads/sitetus` only when wp-content is not writable), `Runner` (the core's `Collector::run` with WordPress facts), `WordPressChecks` (core/plugin/theme updates, config flags, admins, auto-updates, themes, plugin inventory), `Rest\CollectEndpoint` (`GET /wp-json/sitetus/v1/collect`), `Admin\SettingsPage` (Settings → Sitetus).
- Identity never ships in the plugin: the panel shows a **pairing code** (public key, project key, nonce file name, modules — no secret) that the owner pastes into WP admin.
- `src/Readers/` + `src/SeoPluginModule.php` is the plugin-native `seo_plugin` module (0.2.0): `PluginReader` is the contract (`slug`, `isActive`, `version`, `supports`, `read`), `AbstractReader` holds the shared helpers (plugin list, options, prepared read-only `$wpdb` queries under a `ReadBudget` of 2 s), and `YoastReader` / `RankMathReader` / `AioseoReader` know one plugin's storage each (the layout is documented in each class header with the version it was verified on). `SeoPluginModule` picks the first active reader in `READERS`, counts active SEO plugin *families* (`FAMILIES`, a premium add-on counts once), bounds the public post types (20 × 32 chars, attachments excluded) and returns the plugin-independent shape the panel ingests. **Adding a plugin = one reader class + one entry in `READERS` (+ its slugs in `FAMILIES`)**; nothing else changes. The module is listed in `Settings::PLUGIN_MODULES`, so it is always offered on the settings page (opt-in, off by default) whatever the pairing code's core module list says.
- `src/Readers/Security/` + `src/SecurityPluginModule.php` is the plugin-native `security_plugin` module (0.4.0): `SecurityPluginReader` is a second contract (same `slug` / `isActive` / `version` / `supports`, but `read()` takes no arguments — a security plugin's facts are site wide, whereas the SEO contract is per post type, so `PluginReader` was left untouched rather than generalised). `AbstractSecurityReader` uses the shared `ReadsPluginStorage` trait plus a bounded administrator list and usermeta enrolment counts; `WordfenceReader`, `KadenceSecurityReader`, `AiosReader`, `ReallySimpleSecurityReader`, `LimitLoginAttemptsReader` and `TwoFactorReader` know one plugin's storage each. Unlike the SEO module, every supported active reader runs: each block (`scan`, `firewall`, `two_factor`, `hardening`, `login_pressure`) comes from the first reader in `READERS` that keeps it and names that plugin; `conflicts` counts active firewalls and login limiters per family (`FAMILIES` + `FAMILY_ROLES`, a reader's own `roles` answer wins). Never an IP, a user name, a path, a finding text or a key. Tests: `php -d zend.assertions=1 tests/security.php`.
- `src/CoreIntegrityModule.php` is the plugin-native `core_integrity` module (0.5.0): the core files on disk against the official MD5 list of the installed release. The list comes from WordPress' own `get_core_checksums($version, $locale)` (loaded from `wp-admin/includes/update.php` when the REST request has not loaded it) — **the one outbound request this plugin makes**: the site asks api.wordpress.org once per signed request that includes the module (a second time for `en_US` when the package locale has no list, flagged `locale_fallback`; `wp-includes/version.php` is then left out because the language line differs). Version and locale are read from `wp-includes/version.php` (`$wp_version`, `$wp_local_package`), not from the site-language setting. Output: `modified` / `missing` / `unexpected` as `{count, paths}` (20 relative paths at most, 200 bytes each), `root_extra` as `{count, names}`, `version`, `locale`, `locale_fallback`, `checked`, `partial`, `unreadable`, `duration_ms` — never a hash, content or mtime. `wp-content/` is skipped; root-level `wp-config-sample.php`, `*.html` and `*.txt` are ignored; `unexpected` looks at PHP-family extensions under `wp-admin/` and `wp-includes/` only. Budgets: `MAX_FILES` 5,000, `MAX_BYTES` 256 MB, `BUDGET_SECONDS` 5 (the clock starts after the list arrived), `MAX_WALK_ENTRIES` 20,000 → `partial: true`. The directory walk runs first (it only lists directories), then the comparison walks the sorted official list circularly from `startOffset()` = (UTC day number × `ROTATION_STRIDE` 150) mod N: stateless, and at the slowest observed rate (172 files per request on a cold disk) every file is reached within ceil(N / 150) days (23 for a 7.x core); a run that finishes is unaffected by the offset. The request also carries WordPress' standard User-Agent (version and site address) — every text that describes the request says so; the panel offers the module unticked. Links are never followed (counted in `unreadable`). No list → `unsupported: checksums_unavailable`. Writes nothing and caches nothing (`tests/writes.php` runs it with every other module). Tests: `php -d zend.assertions=1 tests/core_integrity.php`.

## What the plugin writes

The plugin never changes content, users, posts, themes or other plugins' data. It writes exactly three things of its own, and `uninstall.php` removes all three (on every site of a multisite network):

- the option `sitetus_collector` (`Plugin::OPTION`) — the pairing and the module choice;
- the option `sitetus_last_pull` (`Plugin::OPTION_LAST_PULL`) — time and status of the last signed request;
- the replay-protection record — the nonce file named in the pairing code plus a web-deny `.htaccess`, in the `NonceDirectory` folder.

`tests/writes.php` holds this line: it fails when any other option name is written, when a WordPress write API appears in the source, when a signed request creates any other file, or when uninstall leaves something behind. `readme.txt` and the settings page say the same thing in the owner's words; keep the three in step.

## Develop

```bash
php bin/sync-core.php              # refresh the vendored core from ../sitetus-collector
php bin/test.php --envelope=/tmp/envelope.json   # every tests/*.php with assertions on (what CI runs); smoke's envelope saved
php -d zend.assertions=1 -d assert.exception=1 tests/readers.php   # one file on its own
php bin/build-zip.php              # dist/sitetus.zip (compiles languages/*.mo first)
```

`bin/test.php` discovers the test files, so a new `tests/*.php` runs locally and in CI without being listed anywhere.

To run the `seo_plugin` module against a real install (WP-CLI): `wp eval 'require_once ABSPATH."wp-admin/includes/plugin.php"; require "/path/to/sitetus-wordpress/includes/autoload.php"; echo json_encode(Sitetus\WP\SeoPluginModule::run());'`.

The envelope in `/tmp/envelope.json` must pass the panel's `EnvelopeValidator` (checked from the panel repo).

## Release

`readme.txt` Stable tag, `sitetus.php` Version and `SITETUS_PLUGIN_VERSION` move together. The zip's single top-level folder is `sitetus/`.

## License

GPLv2 or later — `LICENSE`. Copyright (C) 2026 Codemarkt Yazılım Sanayi Ticaret A.Ş. The vendored collector core under `includes/collector/` is the `sitetus/collector` package (MIT, Codemarkt), which is GPL-compatible.
