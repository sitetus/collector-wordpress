=== Sitetus ===
Contributors: codemarkt
Tags: monitoring, security, updates, health, inventory
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets the Sitetus monitoring panel read this site's health, update status and inventory through signed, read-only requests. Nothing leaves the server on its own.

== Description ==

Sitetus is a monitoring platform for websites. This plugin is its read-only collector for WordPress: once you pair the site with your Sitetus panel, the panel can ask the site — a few times a day, with a cryptographically signed request — for:

* core, plugin and theme update status (read from WordPress' own update cache, never from wordpress.org),
* the list of installed plugins (directory names and versions),
* configuration posture as true/false flags (debug display, file editor, auto-updates, default table prefix, default salts),
* the number of administrator accounts and whether an account named "admin" exists,
* core posture read from your own settings: whether registration is open and with which default role, which non-administrator roles hold a dangerous capability (role slugs and capability names only), whether an administrator is missing from the user list, how many administrators carry an application password, the must-use plugin file names and drop-ins present, WP_DEBUG_LOG / SCRIPT_DEBUG / environment type as flags, how far behind WP-Cron is, whether a "coming soon" or maintenance mode (Elementor, SeedProd, WooCommerce launch gate, core .maintenance) hides the site from visitors, and whether one of the common backup plugins is active,
* PHP and server health (disk usage, php.ini hygiene, OPcache, load),
* optional modules you switch on: file trace (names, dates, sizes; web-shell signatures scanned locally), archive scan, permissions, AI bot traffic counters,
* an optional SEO plugin module that reads the records your own SEO plugin keeps (Yoast SEO, Rank Math or All in One SEO): how many published items are set to noindex per post type, which post types are closed to indexing as a whole, how many items receive no internal link, whether the plugin's sitemap and organization settings are filled, and whether more than one SEO plugin is active. Counts and yes/no only — no title, URL, author or keyword leaves the site.
* three more optional modules (0.4.0): **security plugin records** (Wordfence, Kadence Security, All-In-One Security, Really Simple Security, Limit Login Attempts Reloaded, Two Factor — open issue counts and last-scan age from the plugin's own scanner, firewall mode, administrators covered by two-factor authentication, hardening switches, total login counters; attack logs, IP addresses, user names and vulnerability lists are never read), **mail delivery** (WP Mail SMTP, FluentSMTP — transport class, provider configured, sent/error counts of the last seven days) and **cache posture** (active page-cache plugins, whether page cache is on, which plugin owns advanced-cache.php).
* an optional **core file integrity** module (0.5.0) that compares the WordPress core files on disk with the official checksum list of the installed version and language package: how many core files differ from the official release, how many are missing, how many PHP files sit in wp-admin and wp-includes without being part of the release, and how many PHP files in the site root are not part of the release (wp-config.php left out) — each with at most 20 file names relative to the WordPress folder — plus the WordPress version, the package language, the number of files compared and whether the comparison was complete. wp-content is not compared; the sample configuration, readme and licence files in the site root are ignored. No checksum, file content or file date leaves the site, and no file is repaired, replaced or deleted. To get the official list the site asks WordPress.org; see "External services".

**What the plugin never does**

* It never sends anything on its own. The panel calls this site; unsigned or invalid requests receive a 404 with no body. One optional module, core file integrity, makes the site ask WordPress.org for the official checksum list while it answers such a request (see "External services"); no other module contacts another server.
* It never reads wp-config values, database credentials, salts, user names or e-mail addresses.
* It never transmits file contents or log lines.
* It never changes your content, users, posts, themes or other plugins' data. It writes only three things of its own: its configuration option (the pairing and the module choice), its last-request option (when the panel last called and whether the call was answered) and its replay-protection record (a small file, with a web-deny rule beside it, in the plugin's own folder under wp-content). Deleting the plugin removes all three.
* It never updates itself or runs code the panel sends — the panel cannot send code.

**Pairing**

1. In the Sitetus panel open the site, go to the Collector page and copy the pairing code.
2. In WordPress go to Settings → Sitetus, paste the code, choose the optional modules, click Pair.
3. Back in the panel click "Verify connection".

The pairing code contains the panel's public key, an identifier for the site and the module list. It contains no secret.

== External services ==

The plugin exposes one REST endpoint (`/wp-json/sitetus/v1/collect`) that only the Sitetus panel you paired can use: every request must carry a valid signature made with the panel's private key, which never leaves the panel. The data described above is returned to that panel in response to its requests; see https://sitetus.com for the panel's terms and privacy policy.

With every optional module but one, the plugin connects to no external service. The exception is the **core file integrity** module. It is off unless you choose it: the panel offers it unticked when you create a pairing code, a pairing code that does not list it leaves it off, and on Settings → Sitetus you can switch it on or off at any time. On a site that was paired before version 0.5.0 it stays off until you select it there.

* Service: the WordPress.org API (`api.wordpress.org/core/checksums/1.0/`), the same service WordPress itself contacts for update checks. It is called through WordPress' own function `get_core_checksums()`.
* When: each time the panel's signed request includes the module — once per request, a second time only when no list exists for the installed language package and the English (en_US) list is asked for instead. Nothing is cached.
* What is sent: the WordPress version and the locale of the installed language package. As with every request WordPress makes, the request also carries WordPress' standard User-Agent header (WordPress version and the site address) and comes from your server's IP address. No file name, checksum or other site data is sent to WordPress.org.
* Why: the official list is what the core files are compared with; without it the module reports "unsupported" instead of a verdict.
* Terms and privacy: https://wordpress.org/about/privacy/

While the module is not selected, the plugin makes no outbound request at all.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the plugin directory.
2. Activate it.
3. Go to Settings → Sitetus and pair the site with the code from your panel.

== Frequently Asked Questions ==

= Does the plugin slow the site down? =

No. It does nothing on page views. Work happens only while answering a signed request from the panel, and every optional module runs under its own time budget.

= Can the panel change anything on my site? =

No. The plugin contains no code path that changes content, users, posts, themes or other plugins' data, or that executes instructions from a request. Answering a signed request writes only the plugin's own last-request option and its replay-protection record; the pairing itself is stored in the plugin's own configuration option when you pair the site in WP admin.

= What if I delete the plugin? =

Everything the plugin wrote is removed: its configuration option (the pairing), its last-request option and the replay-protection record with its folder. The panel then gets a 404 and shows the site as disconnected.

== Changelog ==

= 0.5.1 =
* Vendored collector core updated to 0.7.2: comments and documentation only, no change in behaviour or in what the plugin sends.

= 0.5.0 =
* New opt-in module **core file integrity** (`core_integrity`): compares the WordPress core files with the official checksum list of the installed version and language package. Reports how many core files differ, how many are missing, how many PHP files in wp-admin and wp-includes are not part of the release and how many PHP files in the site root are not part of the release (information only; wp-config.php left out), each with at most 20 relative file names. wp-content is skipped; the sample configuration, readme and licence files in the site root are ignored. The comparison runs under a budget (5,000 files, 256 MB read, 5 seconds) and says so when it could not finish; its starting file moves forward every day, so a slow server that cannot finish in one request still has every file compared over successive days. Symbolic links are never followed. Read-only: nothing is repaired, replaced, deleted, cached or written.
* The module is the plugin's first outbound request: the site asks WordPress.org (api.wordpress.org, through WordPress' own `get_core_checksums()`) for the official list; the request carries the WordPress version and the package locale and, like every request WordPress makes, the standard WordPress User-Agent header with the version and the site address. The module is off unless you choose it. The "External services" section and the "never does" statement describe it; with the module off, the plugin still contacts no external service.

= 0.4.1 =
* The optional bot traffic module also counts five more documented AI agents: Google-Agent, DuckAssistBot, MistralAI-Index, MistralAI-User and MistralAI-Training.
* The "what the plugin writes" statement is now exact, here and on the settings page: the plugin never changes content, users, posts, themes or other plugins' data; it writes only its own configuration option, its own last-request option and its replay-protection record, and deleting the plugin removes all three. A test now fails if any other option is written. The settings-page notice about an unwritable folder names the right folder (the plugin's own folder under wp-content).
* Deleting the plugin on a multisite network now removes the plugin's options and replay-protection record on every site of the network, not only the current one.
* Tested up to WordPress 7.1.
* Development: one runner (`bin/test.php`) discovers and runs every test file; CI uses it on the whole PHP matrix. Not shipped in the zip.

= 0.4.0 =
* Three new opt-in modules, each reading the site's own plugins through versioned readers, counts and flags only. **Security plugin records** (`security_plugin`): Wordfence, Kadence Security (formerly Solid / iThemes Security), All-In-One Security, Really Simple Security, Limit Login Attempts Reloaded and Two Factor — open issue counts and last-scan age from the plugin's own scanner, firewall mode, administrators covered by two-factor authentication, hardening switches, total login counters and conflicting families; attack logs, IP addresses, user names and vulnerability lists are never read. **Mail delivery** (`mail_delivery`): WP Mail SMTP and FluentSMTP — transport class (PHP mail, SMTP, provider API), whether the provider is configured, sent and error counts of the last seven days; without a plugin the transport is derived from core. **Cache posture** (`cache_posture`): active page-cache plugins, whether the leading plugin's page cache is on, and which plugin owns advanced-cache.php (signature only).
* Collector core 0.7.0: maintenance mode is read from the marker file at Level 1 (Laravel `storage/framework/down`, generic `.maintenance` younger than ten minutes); the plugin keeps reporting WordPress maintenance and "coming soon" states through its own `wp_hidden_mode` row.

= 0.3.0 =
* Core slice (nine new health rows, counts and flags only): registration setting and default role; non-administrator roles holding a dangerous capability beyond WordPress defaults (role slugs and capability names only); administrators present in the capability meta but missing from the user list; number of administrators with an application password; must-use plugin file names and the drop-ins present in wp-content; WP_DEBUG_LOG (and whether the log would land under the web root), SCRIPT_DEBUG, SAVEQUERIES and the environment type; WP-Cron lag (oldest overdue event in seconds, overdue count, DISABLE_WP_CRON); "coming soon" / maintenance mode hiding the site from visitors (Elementor, SeedProd, WooCommerce launch gate, a core .maintenance file still honoured); whether one of the common backup plugins is active. No user name, e-mail, title, URL, path or hook name leaves the site.

= 0.2.1 =
* Collector core 0.6.1: control files under wp-content/uploads (.htaccess, .user.ini, web.config, php.ini) are classified before they count as a threat — the protective "deny from all" / "engine off" files WooCommerce, Wordfence, Sucuri and many hosts write there no longer report as executable uploads. The first 4 KB are read locally; only a class (enabling / protective / unknown) leaves, never the content.

= 0.2.0 =
* New optional module "SEO plugin records" (Settings → Sitetus): reads the active SEO plugin's own records — Yoast SEO (indexable table and settings), Rank Math (post meta, Link Counter table and settings), All in One SEO (its posts table and JSON settings) — and reports noindex counts per post type, post types closed to indexing, orphaned content, sitemap and organization settings, index completeness and the number of active SEO plugins. Counts and yes/no only. Off by default; always offered on the settings page.
* Readers are versioned: a plugin version outside the known range is reported as unsupported instead of guessed.

= 0.1.2 =
* Collector core 0.6.0 (Level 2 posture rows for Laravel installs: storage link flags, session driver name; no change to what a WordPress site reports).

= 0.1.1 =
* Collector core 0.5.2: the optional bot traffic module also counts Meta-WebIndexer, Amzn-SearchBot and Amzn-User.
* Replay-protection file under wp-content/sitetus; themes join the inventory; settings copy says "security keys".

= 0.1.0 =
* First release: pairing code, signed REST endpoint, WordPress update/config/admin checks, plugin inventory, optional read-only modules shared with the Sitetus collector core.
