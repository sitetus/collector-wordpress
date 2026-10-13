<?php
/**
 * Smoke test without WordPress: stubs the handful of WP functions the plugin
 * calls, pairs a fake site, sends a correctly signed request through the
 * REST handler's core path (Runner) and prints the envelope. Run:
 *
 *   php tests/smoke.php > /tmp/envelope.json
 *
 * The panel's EnvelopeValidator must accept the output (checked in the
 * panel repo). Requires the sodium extension.
 */

declare(strict_types=1);

$root = sys_get_temp_dir().'/sitetus-wp-smoke-'.bin2hex(random_bytes(4));
mkdir($root.'/wp-content/uploads', 0777, true);
mkdir($root.'/wp-content/plugins/akismet', 0777, true);
file_put_contents($root.'/wp-config.php', "<?php\n");
file_put_contents($root.'/index.php', "<?php\n");
file_put_contents($root.'/wp-content/plugins/akismet/akismet.php', "<?php\n");

define('ABSPATH', $root.'/');
define('WP_CONTENT_DIR', $root.'/wp-content');
define('WP_DEBUG', true);
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

function get_option(string $name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option(string $name, $value, $autoload = null): bool { $GLOBALS['options'][$name] = $value; return true; }
function delete_option(string $name): bool { unset($GLOBALS['options'][$name]); return true; }
function wp_upload_dir($time = null, bool $create = true): array { return ['basedir' => ABSPATH.'wp-content/uploads']; }
function untrailingslashit(string $value): string { return rtrim($value, '/\\'); }
function wp_mkdir_p(string $path): bool { return is_dir($path) || mkdir($path, 0777, true); }
function wp_is_writable(string $path): bool { return is_writable($path); }
function get_bloginfo(string $show): string { return $show === 'version' ? '6.6.2' : ''; }
function get_site_transient(string $name) {
    switch ($name) {
        case 'update_core':
            return (object) ['updates' => [(object) ['response' => 'upgrade', 'current' => '6.7.1']]];
        case 'update_plugins':
            return (object) ['response' => ['akismet/akismet.php' => (object) []]];
        case 'update_themes':
            return (object) ['response' => []];
        default:
            return false;
    }
}
function get_plugins(): array { return ['akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.3']]; }
function is_plugin_active(string $file): bool { return isset(get_plugins()[$file]); }
function get_post_types(array $args = [], string $output = 'names'): array { return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment']; }
function get_users(array $args): array { return [(object) ['ID' => 1, 'user_login' => 'admin']]; }
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

require dirname(__DIR__).'/includes/autoload.php';

use Sitetus\WP\PairingCode;
use Sitetus\WP\Runner;
use Sitetus\WP\Settings;
use Sitetus\Collector\RequestVerifier;

$pair = sodium_crypto_sign_keypair();
$payload = [
    'v' => 1,
    'project_key' => 'smoketest1234567890a',
    'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
    'algo' => 'ed25519',
    'expires_at' => null,
    'nonce_file' => '.sitetus-smoke123456.json',
    'modules' => ['files', 'archives', 'permissions', 'bot_traffic'],
    'site' => 'wp.example',
    'panel' => 'https://panel.example',
];
$code = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

$decoded = PairingCode::decode($code);
assert($decoded !== null, 'pairing code decodes');
Settings::pair($decoded, ['files', 'permissions', 'seo_plugin', 'mail_delivery', 'cache_posture', 'security_plugin']);
assert(Settings::get()['modules'] === ['files', 'permissions', 'seo_plugin', 'mail_delivery', 'cache_posture', 'security_plugin'], 'module narrowing');
assert(array_slice(Settings::get()['modules_allowed'], 0, 5) === ['files', 'archives', 'permissions', 'bot_traffic', 'seo_plugin'], 'plugin-native module always offered');
assert(array_diff(['mail_delivery', 'cache_posture'], Settings::get()['modules_allowed']) === [], '0.4.0 plugin-native modules always offered');

$now = time();
$nonce = bin2hex(random_bytes(16));
$path = '/wp-json/sitetus/v1/collect';
$material = RequestVerifier::signatureMaterial('GET', $path, $payload['project_key'], (string) $now, $nonce, '');
$headers = [
    'x-sitetus-key' => $payload['project_key'],
    'x-sitetus-timestamp' => (string) $now,
    'x-sitetus-nonce' => $nonce,
    'x-sitetus-algo' => 'ed25519',
    'x-sitetus-signature' => base64_encode(sodium_crypto_sign_detached($material, sodium_crypto_sign_secretkey($pair))),
];

$anonymous = Runner::run('GET', $path, [], '', $now);
assert($anonymous['status'] === 404 && $anonymous['body'] === null, 'unsigned → bodiless 404');

$result = Runner::run('GET', $path, $headers, '', $now);
assert($result['status'] === 200, 'signed → 200: '.json_encode($result));
assert(in_array('seo_plugin', $result['body']['modules']['enabled'], true), 'seo_plugin joins the enabled modules');
assert(($result['body']['modules']['seo_plugin']['unsupported'] ?? null) === 'no_seo_plugin', 'no SEO plugin → unsupported: no_seo_plugin');
assert($result['body']['modules']['seo_plugin']['active_seo_plugins'] === ['count' => 0, 'slugs' => []], 'no active SEO plugin counted');

// 0.4.0: without a delivery plugin the transport is the server's own mail(); a bare site has no page cache.
assert($result['body']['modules']['mail_delivery'] === ['plugin' => null, 'transport' => 'php_mail', 'provider_configured' => false, 'errors_7d' => null, 'sent_7d' => null, 'logging_enabled' => null], 'mail_delivery without a plugin: '.json_encode($result['body']['modules']['mail_delivery'] ?? null));
assert($result['body']['modules']['cache_posture'] === ['page_cache_plugins' => [], 'page_cache_enabled' => null, 'advanced_cache_dropin_owner' => null, 'object_cache_dropin' => false, 'multiple_page_caches' => false], 'cache_posture on a bare site: '.json_encode($result['body']['modules']['cache_posture'] ?? null));

// 0.4.0: without a security plugin the module says so and still counts conflicts (none).
assert(in_array('security_plugin', $result['body']['modules']['enabled'], true), 'security_plugin joins the enabled modules');
assert($result['body']['modules']['security_plugin'] === ['active_security_plugins' => ['count' => 0, 'slugs' => []], 'conflicts' => ['firewalls' => 0, 'login_limiters' => 0], 'unsupported' => 'no_security_plugin'], 'security_plugin on a bare site: '.json_encode($result['body']['modules']['security_plugin'] ?? null));

// 0.5.0: core_integrity is always offered, and absent from the envelope until the owner selects it.
assert(in_array('core_integrity', Settings::get()['modules_allowed'], true), 'core_integrity always offered');
assert(! in_array('core_integrity', $result['body']['modules']['enabled'], true) && ! isset($result['body']['modules']['core_integrity']), 'core_integrity not selected → not in the envelope');

// 0.3.0: the core slice rides in the same health list; every detail stays flat and bounded.
$ids = array_column($result['body']['health']['checks'], 'id');
foreach (['wp_registration', 'wp_privileged_roles', 'wp_hidden_admins', 'wp_admin_app_passwords', 'wp_mu_plugins', 'wp_debug_posture', 'wp_cron_lag', 'wp_hidden_mode', 'wp_backup_plugin'] as $id) {
    assert(in_array($id, $ids, true), "core row {$id} present");
}
foreach ($result['body']['health']['checks'] as $check) {
    foreach ((array) ($check['detail'] ?? []) as $key => $value) {
        assert(preg_match('/^[a-z0-9_]{1,64}$/', (string) $key) === 1 && (is_scalar($value) || $value === null) && (! is_string($value) || strlen($value) <= 200), $check['id'].' detail stays flat and bounded');
    }
}

$replay = Runner::run('GET', $path, $headers, '', $now + 5);
assert($replay['status'] === 403 && $replay['headers']['X-Sitetus-Error'] === 'nonce', 'replay → 403 nonce');

fwrite(STDERR, "smoke ok\n");
echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
