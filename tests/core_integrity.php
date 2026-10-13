<?php
/**
 * core_integrity tests without WordPress (plugin 0.5.0): a temporary
 * WordPress-shaped tree, a stubbed get_core_checksums() that answers from
 * a table (and records what it was asked), and the module run against it.
 * A second part sends signed requests through the Runner to see the
 * module in the envelope only when it is selected. Run:
 *
 *   php -d zend.assertions=1 -d assert.exception=1 tests/core_integrity.php
 *
 * The fixture tree lives under the system temp folder (the repository has
 * no scratch folder of its own) and is removed on shutdown, also after a
 * failed assertion. Its files are ordinary-looking source with neutral
 * names, so a virus scanner has no reason to react; if one removes a
 * fixture anyway, the test says so instead of failing on a wrong count.
 */

declare(strict_types=1);

$root = sys_get_temp_dir().'/sitetus-wp-integrity-'.bin2hex(random_bytes(4));
$outside = $root.'-outside';
mkdir($root.'/wp-content/uploads', 0777, true);

/**
 * Removes a file, a link or a whole folder; a path that is already gone
 * (removed by a scanner, or never created) is not an error.
 */
function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path) || @rmdir($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach ((array) @scandir($path) as $entry) {
        if (is_string($entry) && $entry !== '.' && $entry !== '..') {
            removeTree($path.'/'.$entry);
        }
    }

    @rmdir($path);
}

register_shutdown_function(static function () use ($root, $outside): void {
    clearstatcache();
    removeTree($root);
    removeTree($outside);
});

define('ABSPATH', $root.'/');
define('WP_CONTENT_DIR', $root.'/wp-content');
define('WP_DEBUG', false);
define('AUTH_KEY', str_repeat('x', 64));
define('SECURE_AUTH_KEY', str_repeat('x', 64));
define('LOGGED_IN_KEY', str_repeat('x', 64));
define('NONCE_KEY', str_repeat('x', 64));
define('AUTH_SALT', str_repeat('x', 64));
define('SECURE_AUTH_SALT', str_repeat('x', 64));
define('LOGGED_IN_SALT', str_repeat('x', 64));
define('NONCE_SALT', str_repeat('x', 64));
define('SITETUS_PLUGIN_FILE', dirname(__DIR__).'/sitetus.php');
define('SITETUS_PLUGIN_DIR', dirname(__DIR__));

$GLOBALS['options'] = [];
$GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
$GLOBALS['checksums'] = [];
$GLOBALS['checksum_calls'] = [];

function get_option(string $name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option(string $name, $value, $autoload = null): bool { $GLOBALS['options'][$name] = $value; return true; }
function delete_option(string $name): bool { unset($GLOBALS['options'][$name]); return true; }
function wp_upload_dir($time = null, bool $create = true): array { return ['basedir' => ABSPATH.'wp-content/uploads']; }
function untrailingslashit(string $value): string { return rtrim($value, '/\\'); }
function wp_mkdir_p(string $path): bool { return is_dir($path) || mkdir($path, 0777, true); }
function wp_is_writable(string $path): bool { return is_writable($path); }
function get_bloginfo(string $show): string { return $show === 'version' ? '6.6.2' : ''; }
function get_site_transient(string $name) { return false; }
function get_plugins(): array { return []; }
function is_plugin_active(string $file): bool { return false; }
function get_post_types(array $args = [], string $output = 'names'): array { return ['post' => 'post', 'page' => 'page']; }
function get_users(array $args): array { return [(object) ['ID' => 1, 'user_login' => 'owner']]; }
function apply_filters(string $hook, $value) { return $value; }
function wp_is_auto_update_enabled_for_type(string $type): bool { return true; }
function wp_get_themes(): array { return ['twentytwentyfour' => true]; }
function wp_get_theme() {
    return new class {
        public function parent() { return false; }
        public function get_stylesheet(): string { return 'twentytwentyfour'; }
        public function get(string $key): string { return '1.2'; }
    };
}

/**
 * WordPress' own function (wp-admin/includes/update.php): the official
 * list for a version and locale, or false. Here it answers from a table.
 */
function get_core_checksums($version, $locale)
{
    $GLOBALS['checksum_calls'][] = [$version, $locale];

    return $GLOBALS['checksums'][$version][$locale] ?? false;
}

require dirname(__DIR__).'/includes/autoload.php';

use Sitetus\Collector\RequestVerifier;
use Sitetus\WP\CoreIntegrityModule;
use Sitetus\WP\PairingCode;
use Sitetus\WP\Runner;
use Sitetus\WP\Settings;

/**
 * An ordinary-looking PHP source file: a docblock and two small functions
 * named after the file.
 */
function phpSource(string $label, int $limit = 10): string
{
    $name = preg_replace('/[^a-z0-9]+/', '_', strtolower($label));

    return <<<PHP
<?php
/**
 * {$label}: fixture source for the core integrity test.
 *
 * @package Fixture
 */

/**
 * Returns how many items {$label} lists on one page.
 *
 * @return int
 */
function fixture_{$name}_per_page() {
    return {$limit};
}

/**
 * Formats a label for {$label}.
 *
 * @param string \$text Raw label.
 * @return string
 */
function fixture_{$name}_label( \$text ) {
    return ucfirst( trim( \$text ) );
}

PHP;
}

function versionSource(string $version, ?string $package = null): string
{
    $source = "<?php\n/**\n * WordPress Version\n *\n * Contains version information for the current WordPress release.\n *\n * @package Fixture\n */\n\n/**\n * The WordPress version string.\n *\n * @global string \$wp_version\n */\n\$wp_version = '{$version}';\n\n/**\n * Holds the required PHP version.\n *\n * @global string \$required_php_version\n */\n\$required_php_version = '7.2.24';\n";

    return $package === null ? $source : $source."\n/**\n * The language package of this build.\n *\n * @global string \$wp_local_package\n */\n\$wp_local_package = '{$package}';\n";
}

/**
 * Writes a fixture file and reads it back. A file that is not there, or
 * not readable, right after it was written was taken by a virus scanner:
 * that is said plainly instead of surfacing later as a wrong count.
 */
function put(string $relative, string $content): void
{
    $path = ABSPATH.$relative;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    $written = @file_put_contents($path, $content);
    clearstatcache(true, $path);

    if ($written === false || ! is_file($path) || @file_get_contents($path) !== $content) {
        throw new RuntimeException("Fixture file {$relative} was removed or blocked right after it was written. This is antivirus interference with the test fixture under ".ABSPATH.', not a failure of the module; exclude that folder from real-time scanning and run the test again.');
    }
}

/**
 * The files of the fake release: relative path → content.
 *
 * @return array<string, string>
 */
function release(): array
{
    return [
        'index.php' => phpSource('Front controller'),
        'wp-load.php' => phpSource('Bootstrap loader'),
        'wp-settings.php' => phpSource('Settings loader'),
        'wp-config-sample.php' => phpSource('Sample configuration'),
        'readme.html' => "<!DOCTYPE html>\n<html lang=\"en\">\n<head><title>Readme</title></head>\n<body><h1>Readme</h1><p>Fixture release notes.</p></body>\n</html>\n",
        'license.txt' => "Fixture licence text.\nThis program is free software.\n",
        'wp-admin/index.php' => phpSource('Dashboard screen'),
        'wp-admin/includes/file.php' => phpSource('File helpers'),
        'wp-admin/css/common.css' => "body {\n\tmargin: 0;\n\tfont-family: sans-serif;\n}\n",
        'wp-includes/version.php' => versionSource('6.6.2'),
        'wp-includes/plugin.php' => phpSource('Hook helpers'),
        'wp-includes/js/jquery/jquery.js' => "/* Fixture script library. */\n( function () {\n\t'use strict';\n\twindow.fixtureLibrary = { version: '1.0.0' };\n}() );\n",
        'wp-content/index.php' => "<?php\n// Silence is golden.\n",
        'wp-content/plugins/akismet/akismet.php' => phpSource('Bundled comment plugin'),
        'wp-content/plugins/hello.php' => phpSource('Bundled sample plugin'),
        'wp-content/themes/twentytwentyfour/functions.php' => phpSource('Bundled theme functions'),
    ];
}

/**
 * A clean install of the fake release, its official list registered for
 * 6.6.2 / en_US; wp-config.php is the site's own file.
 *
 * @return array<string, string> the official list
 */
function freshSite(): array
{
    foreach ((array) scandir(ABSPATH) as $entry) {
        if (is_string($entry) && $entry !== '.' && $entry !== '..') {
            removeTree(ABSPATH.$entry);
        }
    }

    clearstatcache();
    $official = [];

    foreach (release() as $relative => $content) {
        put($relative, $content);
        $official[$relative] = md5($content);
    }

    put('wp-config.php', phpSource('Site configuration'));

    $GLOBALS['checksums'] = ['6.6.2' => ['en_US' => $official]];
    $GLOBALS['checksum_calls'] = [];

    return $official;
}

$zero = ['count' => 0, 'paths' => []];
$comparable = 9; // release() minus the four wp-content files, the sample config, the readme and the licence

// --- clean tree ------------------------------------------------------------
freshSite();
$section = CoreIntegrityModule::run();
assert(array_keys($section) === ['version', 'locale', 'locale_fallback', 'checked', 'partial', 'unreadable', 'modified', 'missing', 'unexpected', 'root_extra', 'duration_ms'], 'fixed shape: '.json_encode(array_keys($section)));
assert($section['version'] === '6.6.2' && $section['locale'] === 'en_US' && $section['locale_fallback'] === false, 'version and locale from version.php: '.json_encode($section));
assert($section['modified'] === $zero && $section['missing'] === $zero && $section['unexpected'] === $zero && $section['root_extra'] === ['count' => 0, 'names' => []], 'clean tree → zeros: '.json_encode($section));
assert($section['checked'] === $comparable && $section['partial'] === false && $section['unreadable'] === 0, 'every comparable file checked: '.json_encode($section));
assert($GLOBALS['checksum_calls'] === [['6.6.2', 'en_US']], 'one request for the list, version and locale only: '.json_encode($GLOBALS['checksum_calls']));

// --- one modified core file ------------------------------------------------
freshSite();
put('wp-includes/plugin.php', phpSource('Hook helpers', 25)); // one line of the release differs: "return 10;" became "return 25;"
$section = CoreIntegrityModule::run();
assert($section['modified'] === ['count' => 1, 'paths' => ['wp-includes/plugin.php']], 'modified file named by its relative path: '.json_encode($section));
assert($section['missing'] === $zero && $section['unexpected'] === $zero && $section['checked'] === $comparable, 'nothing else reported');
$json = (string) json_encode($section);
assert(strpos($json, 'return 25') === false && strpos($json, 'fixture_hook_helpers') === false && preg_match('/[a-f0-9]{32}/', $json) !== 1 && strpos($json, basename($root)) === false, 'no content, no hash, no absolute path leaves: '.$json);

// --- one missing core file -------------------------------------------------
freshSite();
unlink(ABSPATH.'wp-admin/includes/file.php');
$section = CoreIntegrityModule::run();
assert($section['missing'] === ['count' => 1, 'paths' => ['wp-admin/includes/file.php']] && $section['modified'] === $zero, 'missing file: '.json_encode($section));
assert($section['checked'] === $comparable - 1, 'a missing file is not counted as checked');

// --- unexpected PHP inside the core folders --------------------------------
freshSite();
put('wp-includes/js/extra/helper.php', phpSource('Extra helper'));
put('wp-admin/css/legacy.PHTML', phpSource('Legacy template')); // another executable extension, upper case
put('wp-includes/notes.txt', "Not PHP: ignored.\n");
$section = CoreIntegrityModule::run();
$paths = $section['unexpected']['paths'];
sort($paths, SORT_STRING);
assert($section['unexpected']['count'] === 2 && $paths === ['wp-admin/css/legacy.PHTML', 'wp-includes/js/extra/helper.php'], 'unexpected PHP files in wp-admin and wp-includes: '.json_encode($section));
assert($section['modified'] === $zero && $section['missing'] === $zero && $section['root_extra']['count'] === 0, 'an unexpected file is neither modified nor missing');

// --- root extra (information), wp-config.php left out ----------------------
freshSite();
put('owner-tool.php', phpSource('Owner tool'));
put('site-verification.html', "<!DOCTYPE html>\n<html><body>verification</body></html>\n");
$section = CoreIntegrityModule::run();
assert($section['root_extra'] === ['count' => 1, 'names' => ['owner-tool.php']], 'root PHP outside the release, wp-config.php and non-PHP left out: '.json_encode($section));
assert($section['unexpected'] === $zero, 'the site root is not scanned for unexpected files');

// --- excluded files ---------------------------------------------------------
freshSite();
unlink(ABSPATH.'wp-config-sample.php');
unlink(ABSPATH.'license.txt');
put('readme.html', "<!DOCTYPE html>\n<html lang=\"tr\"><body><h1>Beni oku</h1></body></html>\n");
removeTree(ABSPATH.'wp-content/plugins/akismet');
unlink(ABSPATH.'wp-content/plugins/hello.php');
put('wp-content/themes/twentytwentyfour/functions.php', phpSource('Bundled theme functions', 30));
put('wp-content/uploads/gallery-notes.php', phpSource('Gallery notes')); // another module's business
$section = CoreIntegrityModule::run();
assert($section['modified'] === $zero && $section['missing'] === $zero && $section['unexpected'] === $zero && $section['root_extra']['count'] === 0, 'wp-content and the sample / readme / licence files are ignored: '.json_encode($section));
assert($section['checked'] === $comparable && $section['partial'] === false, 'ignored files do not make the scan partial');

// --- the list caps at 20 names, the count does not --------------------------
freshSite();
for ($i = 0; $i < 25; $i++) {
    put(sprintf('wp-includes/extra-%02d.php', $i), phpSource('Extra module '.$i));
}
$section = CoreIntegrityModule::run();
assert($section['unexpected']['count'] === 25 && count($section['unexpected']['paths']) === CoreIntegrityModule::LIST_MAX, 'count is exact, list capped: '.json_encode($section));
foreach ($section['unexpected']['paths'] as $path) {
    assert(strlen($path) <= CoreIntegrityModule::PATH_MAX_BYTES && $path[0] !== '/' && strpos($path, '\\') === false, 'relative, forward slashes, bounded: '.$path);
}

// --- checksums unavailable → unsupported ------------------------------------
freshSite();
$GLOBALS['checksums'] = [];
put('wp-includes/plugin.php', phpSource('Hook helpers', 25)); // changed, but there is nothing to compare with
$section = CoreIntegrityModule::run();
assert($section === ['unsupported' => 'checksums_unavailable', 'version' => '6.6.2', 'locale' => 'en_US'], 'no official list → unsupported, never a verdict: '.json_encode($section));

// A nightly build: WordPress.org has no list for it.
freshSite();
put('wp-includes/version.php', versionSource('6.8-alpha-59000'));
$section = CoreIntegrityModule::run();
assert($section === ['unsupported' => 'checksums_unavailable', 'version' => '6.8-alpha-59000', 'locale' => 'en_US'], 'nightly → unsupported: '.json_encode($section));

// --- language package -------------------------------------------------------
$official = freshSite();
put('wp-includes/version.php', versionSource('6.6.2', 'tr_TR'));
$GLOBALS['checksums']['6.6.2']['tr_TR'] = ['wp-includes/version.php' => md5(versionSource('6.6.2', 'tr_TR'))] + $official;
$section = CoreIntegrityModule::run();
assert($section['locale'] === 'tr_TR' && $section['locale_fallback'] === false && $section['modified'] === $zero, 'the package locale picks its own list: '.json_encode($section));
assert($GLOBALS['checksum_calls'] === [['6.6.2', 'tr_TR']], 'one request when the locale list exists');

// The locale has no list: en_US is asked, the fallback is flagged and the language line of version.php is not held against the site.
unset($GLOBALS['checksums']['6.6.2']['tr_TR']);
$GLOBALS['checksum_calls'] = [];
$section = CoreIntegrityModule::run();
assert($section['locale'] === 'en_US' && $section['locale_fallback'] === true, 'locale fallback flagged: '.json_encode($section));
assert($section['modified'] === $zero && $section['checked'] === $comparable - 1, 'version.php is left out under the fallback: '.json_encode($section));
assert($GLOBALS['checksum_calls'] === [['6.6.2', 'tr_TR'], ['6.6.2', 'en_US']], 'second request only for the fallback');

// --- budget exhaustion → partial --------------------------------------------
freshSite();
put('wp-includes/plugin.php', phpSource('Hook helpers', 25)); // changed late in the list
$section = CoreIntegrityModule::run(['max_files' => 3]);
assert($section['partial'] === true && $section['checked'] === 3, 'file budget: what was checked, flagged partial: '.json_encode($section));

$section = CoreIntegrityModule::run(['max_bytes' => 600]);
assert($section['partial'] === true && $section['checked'] > 0 && $section['checked'] < $comparable, 'byte budget: '.json_encode($section));

$section = CoreIntegrityModule::run(['seconds' => -1.0]);
assert($section['partial'] === true && $section['checked'] === 0 && $section['modified'] === $zero && $section['unexpected'] === $zero, 'spent clock: nothing claimed: '.json_encode($section));

put('wp-includes/zz-extra.php', phpSource('Late extra'));
$section = CoreIntegrityModule::run(['max_walk_entries' => 2]);
assert($section['partial'] === true && $section['checked'] === $comparable && $section['modified']['count'] === 1, 'walk budget: the comparison stands, the walk is partial: '.json_encode($section));

// --- the start rotates with the UTC day --------------------------------------
// Sorted comparable list (9): index.php, wp-admin/css/common.css, wp-admin/includes/file.php, wp-admin/index.php,
// wp-includes/js/jquery/jquery.js, wp-includes/plugin.php, wp-includes/version.php, wp-load.php, wp-settings.php.
// offset = (day × 150) mod 9 → day 0 starts at index 0, day 1 at index 6, day 2 at index 3.
$day = 86400;
assert(CoreIntegrityModule::startOffset(9, 0) === 0 && CoreIntegrityModule::startOffset(9, $day) === 6 && CoreIntegrityModule::startOffset(9, $day + 3600) === 6 && CoreIntegrityModule::startOffset(9, 2 * $day) === 3, 'offset follows the day number, not the hour');
assert(CoreIntegrityModule::startOffset(3335, 20000 * $day) !== CoreIntegrityModule::startOffset(3335, 20001 * $day) && CoreIntegrityModule::startOffset(0, $day) === 0, 'consecutive days differ on a real-sized list; an empty list has no offset');

$official = freshSite();
foreach (array_keys($official) as $relative) {
    if (substr($relative, -4) === '.php' && strpos($relative, 'wp-content/') !== 0 && $relative !== 'wp-includes/version.php') {
        put($relative, phpSource('Changed '.$relative, 99));
    }
}
$dayZero = CoreIntegrityModule::run(['max_files' => 1], 0);
$dayOne = CoreIntegrityModule::run(['max_files' => 1], $day);
assert($dayZero['modified']['paths'] === ['index.php'] && $dayOne['modified']['paths'] === [] && $dayOne['checked'] === 1 && $dayOne['partial'] === true, 'day 0 starts at the first file, day 1 at another (version.php, unchanged): '.json_encode([$dayZero, $dayOne]));
$dayTwo = CoreIntegrityModule::run(['max_files' => 1], 2 * $day);
assert($dayTwo['modified']['paths'] === ['wp-admin/index.php'], 'day 2 starts at a third file: '.json_encode($dayTwo));

// A complete scan is the same whatever the day.
freshSite();
put('wp-settings.php', phpSource('Settings loader', 25));
unlink(ABSPATH.'wp-admin/index.php');
$results = [];
foreach ([0, $day, 2 * $day, 12345 * $day] as $moment) {
    $run = CoreIntegrityModule::run(null, $moment);
    unset($run['duration_ms']);
    $results[] = $run;
}
assert($results[0]['modified'] === ['count' => 1, 'paths' => ['wp-settings.php']] && $results[0]['missing'] === ['count' => 1, 'paths' => ['wp-admin/index.php']] && $results[0]['partial'] === false, 'complete scan: '.json_encode($results[0]));
assert($results[1] === $results[0] && $results[2] === $results[0] && $results[3] === $results[0], 'a complete scan does not depend on the offset');

// A spent budget on day 0 stops before the modified file (the last one in the list); day 1 starts close enough to reach it.
freshSite();
put('wp-settings.php', phpSource('Settings loader', 25));
$dayZero = CoreIntegrityModule::run(['max_files' => 3], 0);
$dayOne = CoreIntegrityModule::run(['max_files' => 3], $day);
assert($dayZero['partial'] === true && $dayZero['checked'] === 3 && $dayZero['modified'] === $zero, 'day 0 misses the modification in the tail: '.json_encode($dayZero));
assert($dayOne['partial'] === true && $dayOne['modified'] === ['count' => 1, 'paths' => ['wp-settings.php']], 'day 1 reaches it: '.json_encode($dayOne));

// --- links are never followed ------------------------------------------------
freshSite();
mkdir($outside);
file_put_contents($outside.'/outside-file.php', phpSource('Outside file'));
unlink(ABSPATH.'wp-load.php');

if (@symlink($outside.'/outside-file.php', ABSPATH.'wp-load.php') && @symlink($outside, ABSPATH.'wp-includes/linked')) {
    $section = CoreIntegrityModule::run();
    assert($section['unreadable'] === 2 && $section['modified'] === $zero && $section['missing'] === $zero && $section['unexpected'] === $zero, 'a link out of the site is skipped and counted, not read: '.json_encode($section));
    assert($section['checked'] === $comparable - 1, 'the linked file is not hashed');
} else {
    fwrite(STDERR, "core_integrity: symlink case skipped (the platform refused to create links)\n");
}

removeTree(ABSPATH.'wp-load.php');
removeTree(ABSPATH.'wp-includes/linked');
removeTree($outside);

// --- a broken or hostile official list ----------------------------------------
freshSite();
$GLOBALS['checksums']['6.6.2']['en_US'] = ['../outside.php' => md5('x'), '/etc/hostname' => md5('x'), 'index.php' => 'not-a-hash', 7 => md5('x')];
assert(CoreIntegrityModule::run() === ['unsupported' => 'checksums_unavailable', 'version' => '6.6.2', 'locale' => 'en_US'], 'a list without one usable entry is no list');

// --- through the Runner: present only when selected ---------------------------
freshSite();
put('wp-includes/plugin.php', phpSource('Hook helpers', 25));

$pair = sodium_crypto_sign_keypair();
$payload = [
    'v' => 1,
    'project_key' => 'integritytest1234567',
    'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
    'algo' => 'ed25519',
    'expires_at' => null,
    'nonce_file' => '.sitetus-integrity123.json',
    'modules' => ['files'],
    'site' => 'wp.example',
    'panel' => 'https://panel.example',
];
$decoded = PairingCode::decode(rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '='));
assert($decoded !== null, 'pairing code decodes');

$path = '/wp-json/sitetus/v1/collect';
$pull = static function () use ($payload, $pair, $path): array {
    $now = time();
    $nonce = bin2hex(random_bytes(16));
    $material = RequestVerifier::signatureMaterial('GET', $path, $payload['project_key'], (string) $now, $nonce, '');

    return Runner::run('GET', $path, [
        'x-sitetus-key' => $payload['project_key'],
        'x-sitetus-timestamp' => (string) $now,
        'x-sitetus-nonce' => $nonce,
        'x-sitetus-algo' => 'ed25519',
        'x-sitetus-signature' => base64_encode(sodium_crypto_sign_detached($material, sodium_crypto_sign_secretkey($pair))),
    ], '', $now);
};

/**
 * @return array<int, string>
 */
function siteTree(string $root): array
{
    $paths = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }

    sort($paths, SORT_STRING);

    return $paths;
}

Settings::pair($decoded, ['files']);
assert(in_array(CoreIntegrityModule::ID, Settings::get()['modules_allowed'], true), 'always offered on the settings page');
assert(! in_array(CoreIntegrityModule::ID, Settings::get()['modules'], true), 'off until it is selected');

$GLOBALS['checksum_calls'] = [];
$result = $pull();
assert($result['status'] === 200, 'signed → 200');
assert(! in_array(CoreIntegrityModule::ID, $result['body']['modules']['enabled'], true) && ! array_key_exists(CoreIntegrityModule::ID, $result['body']['modules']), 'not selected → not in the envelope');
assert($GLOBALS['checksum_calls'] === [], 'not selected → WordPress.org is not asked');

Settings::updateModules(['files', CoreIntegrityModule::ID]);
$treeBefore = siteTree($root);
$changed = (string) file_get_contents(ABSPATH.'wp-includes/plugin.php');

$result = $pull();
assert($result['status'] === 200 && in_array(CoreIntegrityModule::ID, $result['body']['modules']['enabled'], true), 'selected → enabled');
assert($result['body']['modules'][CoreIntegrityModule::ID]['modified'] === ['count' => 1, 'paths' => ['wp-includes/plugin.php']], 'selected → the section rides in the envelope: '.json_encode($result['body']['modules'][CoreIntegrityModule::ID]));
assert(count($GLOBALS['checksum_calls']) === 1, 'one request per pull');
assert(siteTree($root) === $treeBefore, 'the module creates no file');
assert(file_get_contents(ABSPATH.'wp-includes/plugin.php') === $changed && $changed === phpSource('Hook helpers', 25), 'the modified file is left as it is');

fwrite(STDERR, "core_integrity ok\n");
