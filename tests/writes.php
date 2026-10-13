<?php
/**
 * "What the plugin writes" test without WordPress. The readme and the
 * settings page promise that the plugin writes exactly three things of its
 * own — the option sitetus_collector, the option sitetus_last_pull and the
 * replay-protection record — and that deleting the plugin removes them.
 * This file holds that promise three ways:
 *
 *   1. source scan: no WordPress write API in the shipped code, and every
 *      update_option / delete_option call names one of the two options;
 *   2. behaviour: pairing, a module change, a signed request through the
 *      REST handler, a replay and an unsigned request are run against
 *      recording stubs — only the two option names are written and only
 *      the replay-protection folder appears on disk;
 *   3. uninstall.php (single site and multisite) and "Disconnect" leave
 *      no option and no file behind.
 *
 * Run:
 *
 *   php -d zend.assertions=1 -d assert.exception=1 tests/writes.php
 */

declare(strict_types=1);

const ALLOWED_OPTIONS = ['sitetus_collector', 'sitetus_last_pull'];

$pluginRoot = dirname(__DIR__);

// ---------------------------------------------------------------- 1. source scan

/**
 * @return array<int, string> shipped PHP files (what build-zip.php packs, minus languages)
 */
function shippedPhpFiles(string $pluginRoot): array
{
    $files = [$pluginRoot.'/sitetus.php', $pluginRoot.'/uninstall.php'];

    foreach (['src', 'includes'] as $folder) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginRoot.'/'.$folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (substr($file->getFilename(), -4) === '.php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

function withoutComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

$forbiddenCalls = '/(?<![\w>:$])('
    .'add_option|update_site_option|add_site_option|delete_site_option|update_network_option|add_network_option|delete_network_option'
    .'|set_transient|set_site_transient|delete_transient|delete_site_transient'
    .'|(?:update|add|delete)_(?:user|post|term|comment|site)_meta|(?:update|add|delete)_metadata'
    .'|wp_insert_\w+|wp_update_\w+|wp_delete_\w+|wp_trash_\w+|wp_set_\w+|wp_create_\w+|wp_schedule_\w+|wp_cache_(?:set|add|delete|flush)'
    .'|register_setting|switch_theme|activate_plugin|deactivate_plugins|delete_plugins|dbDelta|wp_mail'
    .')\s*\(/';
$forbiddenQueries = '/->\s*(insert|update|delete|replace|query)\s*\(/';
$optionCalls = '/(?<![\w>:$])(update_option|delete_option)\s*\(\s*([^,)]+)/';
$allowedOptionArguments = ['Plugin::OPTION', 'Plugin::OPTION_LAST_PULL', "'sitetus_collector'", "'sitetus_last_pull'"];
$scanned = 0;
$optionCallCount = 0;

foreach (shippedPhpFiles($pluginRoot) as $file) {
    $relative = str_replace('\\', '/', substr($file, strlen($pluginRoot) + 1));
    $code = withoutComments((string) file_get_contents($file));
    $scanned++;

    assert(preg_match($forbiddenCalls, $code, $match) !== 1, "{$relative} calls a WordPress write API: ".($match[1] ?? ''));

    // $wpdb writes; the multisite loop in uninstall.php and the REST response object are the only "->" calls that are not reads.
    assert(preg_match($forbiddenQueries, $code, $match) !== 1, "{$relative} runs a writing database call: ".($match[1] ?? ''));

    preg_match_all($optionCalls, $code, $calls, PREG_SET_ORDER);

    foreach ($calls as $call) {
        $optionCallCount++;
        assert(in_array(trim($call[2]), $allowedOptionArguments, true), "{$relative}: {$call[1]}() on an option that is not the plugin's own: ".trim($call[2]));
    }
}

assert($scanned > 20, 'the source scan saw the shipped tree');
assert($optionCallCount >= 8, 'the source scan found the option calls it is meant to check: '.$optionCallCount);

// ---------------------------------------------------------------- stubs (recording)

$root = sys_get_temp_dir().'/sitetus-wp-writes-'.bin2hex(random_bytes(4));
mkdir($root.'/wp-content/uploads', 0777, true);
mkdir($root.'/wp-content/plugins/akismet', 0777, true);
file_put_contents($root.'/wp-config.php', "<?php\n");
file_put_contents($root.'/index.php', "<?php\n");
file_put_contents($root.'/wp-content/plugins/akismet/akismet.php', "<?php\n");

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
define('SITETUS_PLUGIN_FILE', $pluginRoot.'/sitetus.php');
define('SITETUS_PLUGIN_DIR', $pluginRoot);

$GLOBALS['blog'] = 1;
$GLOBALS['multisite'] = false;
$GLOBALS['options'] = [1 => ['blogname' => 'A site', 'active_plugins' => ['akismet/akismet.php']]];
$GLOBALS['written'] = [];
$GLOBALS['deleted'] = [];
$GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

function get_option(string $name, $default = false) { return $GLOBALS['options'][$GLOBALS['blog']][$name] ?? $default; }
function update_option(string $name, $value, $autoload = null): bool { $GLOBALS['written'][] = $name; $GLOBALS['options'][$GLOBALS['blog']][$name] = $value; return true; }
function add_option(string $name, $value = '', $deprecated = '', $autoload = null): bool { return update_option($name, $value, $autoload); }
function delete_option(string $name): bool { $GLOBALS['deleted'][] = $name; unset($GLOBALS['options'][$GLOBALS['blog']][$name]); return true; }
function is_multisite(): bool { return $GLOBALS['multisite']; }
function get_sites(array $args = []): array { return array_keys($GLOBALS['options']); }
function switch_to_blog(int $id): bool { $GLOBALS['blog_stack'][] = $GLOBALS['blog']; $GLOBALS['blog'] = $id; return true; }
function restore_current_blog(): bool { $GLOBALS['blog'] = array_pop($GLOBALS['blog_stack']); return true; }
function wp_upload_dir($time = null, bool $create = true): array { return ['basedir' => ABSPATH.'wp-content/uploads'.($GLOBALS['blog'] > 1 ? '/sites/'.$GLOBALS['blog'] : '')]; }
function untrailingslashit(string $value): string { return rtrim($value, '/\\'); }
function wp_unslash($value) { return $value; }
function wp_mkdir_p(string $path): bool { return is_dir($path) || mkdir($path, 0777, true); }
function wp_is_writable(string $path): bool { return is_writable($path); }
function get_bloginfo(string $show): string { return $show === 'version' ? '6.6.2' : ''; }
function get_site_transient(string $name) { return false; }
// core_integrity (0.5.0) really compares here: one official file present and changed, one missing.
function get_core_checksums($version, $locale) { return ['index.php' => md5('the official index'), 'wp-load.php' => md5('the official loader')]; }
function get_plugins(): array { return ['akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.3']]; }
function is_plugin_active(string $file): bool { return isset(get_plugins()[$file]); }
function get_post_types(array $args = [], string $output = 'names'): array { return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment']; }
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

final class WP_REST_Request
{
    /** @var array<string, array<int, string>> */
    private $headers;

    /** @param array<string, string> $headers */
    public function __construct(array $headers)
    {
        $this->headers = array_map(static function (string $value): array {
            return [$value];
        }, $headers);
    }

    public function get_headers(): array { return $this->headers; }
    public function get_body(): string { return ''; }
}

final class WP_REST_Response
{
    public $data;

    /** @var int */
    public $status;

    /** @var array<string, string> */
    public $headers = [];

    public function __construct($data = null, int $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public function header(string $name, string $value): void { $this->headers[$name] = $value; }
}

/**
 * @return array<int, string> every file and folder under the fake site root, relative, sorted
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

/**
 * @return array<string, mixed> options of the current site that the plugin did not put there
 */
function foreignOptions(int $blog): array
{
    return array_diff_key($GLOBALS['options'][$blog], array_flip(ALLOWED_OPTIONS));
}

require $pluginRoot.'/includes/autoload.php';

use Sitetus\Collector\RequestVerifier;
use Sitetus\WP\NonceDirectory;
use Sitetus\WP\PairingCode;
use Sitetus\WP\Plugin;
use Sitetus\WP\Rest\CollectEndpoint;
use Sitetus\WP\Settings;

assert([Plugin::OPTION, Plugin::OPTION_LAST_PULL] === ALLOWED_OPTIONS, 'the two option names are the documented ones');

$keyPair = sodium_crypto_sign_keypair();
$payload = [
    'v' => 1,
    'project_key' => 'writestest1234567890',
    'public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
    'algo' => 'ed25519',
    'expires_at' => null,
    'nonce_file' => '.sitetus-writes12345.json',
    'modules' => ['files', 'archives', 'permissions', 'bot_traffic'],
    'site' => 'wp.example',
    'panel' => 'https://panel.example',
];
$code = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
$path = '/wp-json/sitetus/v1/collect';
$_SERVER['REQUEST_URI'] = $path;

$signedHeaders = static function () use ($payload, $keyPair, $path): array {
    $now = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $material = RequestVerifier::signatureMaterial('GET', $path, $payload['project_key'], $now, $nonce, '');

    return [
        'x_sitetus_key' => $payload['project_key'],
        'x_sitetus_timestamp' => $now,
        'x_sitetus_nonce' => $nonce,
        'x_sitetus_algo' => 'ed25519',
        'x_sitetus_signature' => base64_encode(sodium_crypto_sign_detached($material, sodium_crypto_sign_secretkey($keyPair))),
    ];
};

/**
 * What the owner and the panel can make the plugin do: pair (as the settings
 * page does), change modules, an unsigned call, a signed call with every
 * module on, and a replay of it.
 */
$exercise = static function () use ($code, $signedHeaders): void {
    $decoded = PairingCode::decode($code);
    assert($decoded !== null, 'pairing code decodes');

    Settings::pair($decoded, Settings::MODULES);
    NonceDirectory::ensure();
    Settings::updateModules(Settings::MODULES);

    $anonymous = CollectEndpoint::handle(new WP_REST_Request([]));
    assert($anonymous->status === 404 && $anonymous->data === null, 'unsigned → bodiless 404');

    $headers = $signedHeaders();
    $answered = CollectEndpoint::handle(new WP_REST_Request($headers));
    assert($answered->status === 200, 'signed → 200: '.json_encode($answered->data));
    assert(array_diff(Settings::MODULES, $answered->data['modules']['enabled']) === [], 'every module ran: '.json_encode($answered->data['modules']['enabled']));
    assert(($answered->data['modules']['core_integrity']['modified']['count'] ?? null) === 1 && ($answered->data['modules']['core_integrity']['missing']['count'] ?? null) === 1, 'core_integrity compared the tree: '.json_encode($answered->data['modules']['core_integrity'] ?? null));

    $replayed = CollectEndpoint::handle(new WP_REST_Request($headers));
    assert($replayed->status === 403, 'replay → 403');
    assert(Settings::lastPull() !== null && Settings::lastPull()['status'] === 403, 'the last-pull option records the rejected replay');
};

// ---------------------------------------------------------------- 2. behaviour

$foreignBefore = foreignOptions(1);
$treeBefore = siteTree($root);

$exercise();

$touched = array_values(array_unique(array_merge($GLOBALS['written'], $GLOBALS['deleted'])));
sort($touched, SORT_STRING);

assert($touched === ALLOWED_OPTIONS, 'only the two own options are written or deleted: '.json_encode($touched));
assert(foreignOptions(1) === $foreignBefore, 'no other option changed');
assert(array_keys(array_intersect_key($GLOBALS['options'][1], array_flip(ALLOWED_OPTIONS))) === ALLOWED_OPTIONS, 'both own options exist after a pull');

$created = array_values(array_diff(siteTree($root), $treeBefore));
assert($created === ['wp-content/sitetus', 'wp-content/sitetus/.htaccess', 'wp-content/sitetus/'.$payload['nonce_file']], 'only the replay-protection folder appears on disk: '.json_encode($created));
assert(array_diff($treeBefore, siteTree($root)) === [], 'no site file was removed');

// ---------------------------------------------------------------- 3a. Disconnect

Settings::unpair();

assert(foreignOptions(1) === $GLOBALS['options'][1] && $GLOBALS['options'][1] === $foreignBefore, 'Disconnect removes both options and nothing else');
assert(siteTree($root) === $treeBefore, 'Disconnect removes the replay-protection record and its folder');

// ---------------------------------------------------------------- 3b. uninstall, single site

$exercise();
assert(count(siteTree($root)) > count($treeBefore) && Settings::isPaired(), 'paired again before uninstall');

define('WP_UNINSTALL_PLUGIN', 'sitetus/sitetus.php');
$GLOBALS['written'] = [];

require $pluginRoot.'/uninstall.php';

assert($GLOBALS['options'][1] === $foreignBefore, 'uninstall removes both options and nothing else: '.json_encode(array_keys($GLOBALS['options'][1])));
assert(siteTree($root) === $treeBefore, 'uninstall removes the replay-protection record and its folder: '.json_encode(array_values(array_diff(siteTree($root), $treeBefore))));
assert($GLOBALS['written'] === [], 'uninstall writes nothing');

// ---------------------------------------------------------------- 3c. uninstall, multisite

$exercise();

$GLOBALS['multisite'] = true;
$GLOBALS['options'][2] = ['blogname' => 'Second site'];
$GLOBALS['blog'] = 2;
Settings::recordPull(200);
update_option(Plugin::OPTION, $GLOBALS['options'][1][Plugin::OPTION], false);

// The second site's fallback store (wp-content not writable at pairing time) lives under its own uploads folder.
$fallback = $root.'/wp-content/uploads/sites/2/'.NonceDirectory::FOLDER;
mkdir($fallback, 0777, true);
file_put_contents($fallback.'/.htaccess', "Require all denied\n");
file_put_contents($fallback.'/'.$payload['nonce_file'], '{}');
$GLOBALS['blog'] = 1;

require $pluginRoot.'/uninstall.php';

assert($GLOBALS['blog'] === 1, 'uninstall restores the current site');
assert($GLOBALS['options'][1] === $foreignBefore && $GLOBALS['options'][2] === ['blogname' => 'Second site'], 'multisite uninstall removes both options on every site: '.json_encode($GLOBALS['options']));
assert(! is_dir($fallback) && ! is_dir($root.'/wp-content/'.NonceDirectory::FOLDER), 'multisite uninstall removes every replay-protection folder');

echo "writes ok\n";
