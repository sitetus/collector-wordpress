<?php
/**
 * Core-slice tests without WordPress (plugin 0.3.0): stubbed options,
 * roles, users, application passwords, cron table and plugin list, a
 * temporary wp-content tree for mu-plugins and drop-ins, and a fake $wpdb
 * for the hidden-administrator query. Shapes follow what was verified on a
 * real 7.1.2 install (wordpress01, 03.10.2026). Run:
 *
 *   php -d zend.assertions=1 -d assert.exception=1 tests/core.php
 */

declare(strict_types=1);

$root = sys_get_temp_dir().'/sitetus-wp-core-'.bin2hex(random_bytes(4));
mkdir($root.'/wp-content/mu-plugins', 0777, true);

define('ABSPATH', $root.'/');
define('WP_CONTENT_DIR', $root.'/wp-content');
define('WPMU_PLUGIN_DIR', $root.'/wp-content/mu-plugins');
define('SITETUS_PLUGIN_FILE', dirname(__DIR__).'/sitetus.php');
define('SITETUS_PLUGIN_DIR', dirname(__DIR__));

$GLOBALS['options'] = [];
$GLOBALS['plugins'] = [];
$GLOBALS['active'] = [];
$GLOBALS['roles'] = [];
$GLOBALS['admins'] = [1];
$GLOBALS['app_passwords'] = [];
$GLOBALS['cron'] = [];
$GLOBALS['environment'] = 'production';
$GLOBALS['mu'] = [];

function get_option(string $name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function get_plugins(): array { return $GLOBALS['plugins']; }
function is_plugin_active(string $file): bool { return in_array($file, $GLOBALS['active'], true); }
function get_users(array $args): array { return $GLOBALS['admins']; }
function wp_roles(): object { return (object) ['roles' => $GLOBALS['roles']]; }
function wp_is_application_passwords_available(): bool { return true; }
function get_mu_plugins(): array { return $GLOBALS['mu']; }
function _get_cron_array() { return $GLOBALS['cron']; }
function wp_get_environment_type(): string { return $GLOBALS['environment']; }

final class WP_Application_Passwords
{
    public static function get_user_application_passwords(int $userId): array
    {
        return $GLOBALS['app_passwords'][$userId] ?? [];
    }
}

final class FakeWpdb
{
    public string $prefix = 'wp_';

    public string $usermeta = 'wp_usermeta';

    /** @var string|null */
    public $adminMetaCount = '1';

    /** @var array<int, string> */
    public array $log = [];

    public function prepare(string $query, ...$args): string
    {
        $i = 0;

        return (string) preg_replace_callback('/%[sd]/', function (array $match) use (&$i, $args): string {
            $value = $args[$i++] ?? '';

            return $match[0] === '%s' ? "'".str_replace("'", "\\'", (string) $value)."'" : (string) $value;
        }, $query);
    }

    public function get_var(string $query)
    {
        $this->log[] = $query;
        assert(preg_match('/^\s*SELECT\b/i', $query) === 1, 'read-only query: '.$query);

        return $this->adminMetaCount;
    }
}

$GLOBALS['wpdb'] = new FakeWpdb();

require dirname(__DIR__).'/includes/autoload.php';

use Sitetus\WP\BackupPlugins;
use Sitetus\WP\CoreChecks;

function install(string $file, bool $active = true): void
{
    $GLOBALS['plugins'][$file] = ['Name' => $file, 'Version' => '1.0'];

    if ($active) {
        $GLOBALS['active'][] = $file;
    }
}

function row(array $rows, string $id): array
{
    foreach ($rows as $row) {
        if ($row['id'] === $id) {
            return $row;
        }
    }

    throw new RuntimeException("row {$id} missing");
}

// --- Registration ----------------------------------------------------------
$GLOBALS['options'] = ['users_can_register' => '0', 'default_role' => 'subscriber'];
$row = CoreChecks::registration();
assert($row['status'] === 'ok' && $row['detail'] === ['open' => false, 'default_role' => 'subscriber'], 'closed registration');

$GLOBALS['options'] = ['users_can_register' => '1', 'default_role' => 'editor'];
$row = CoreChecks::registration();
assert($row['status'] === 'fail' && $row['value'] === 1 && $row['detail']['default_role'] === 'editor', 'open registration with editor default fails');

$GLOBALS['options'] = ['users_can_register' => '1', 'default_role' => 'subscriber'];
assert(CoreChecks::registration()['status'] === 'ok', 'open registration with subscriber is ok');

$GLOBALS['options'] = ['users_can_register' => '1', 'default_role' => 'Bad Role!'];
assert(CoreChecks::registration()['detail']['default_role'] === 'subscriber', 'an unreadable role name falls back to subscriber');

// --- Privileged roles --------------------------------------------------------
$GLOBALS['roles'] = [
    'administrator' => ['capabilities' => ['manage_options' => true, 'unfiltered_html' => true]],
    'editor' => ['capabilities' => ['unfiltered_html' => true, 'edit_others_pages' => true]],
    // Yoast clones the editor role (seen on wordpress01, 03.10.2026): editor-grade, so unfiltered_html stays a default.
    'wpseo_manager' => ['capabilities' => ['unfiltered_html' => true, 'edit_others_pages' => true, 'wpseo_manage_options' => true]],
    'author' => ['capabilities' => ['edit_posts' => true]],
    'shop_manager' => ['capabilities' => ['manage_options' => false]],
];
$row = CoreChecks::privilegedRoles();
assert($row['status'] === 'ok' && $row['value'] === 0 && $row['detail'] === ['roles' => 0, 'truncated' => false], 'defaults (editor-grade unfiltered_html) are not flagged: '.json_encode($row));

$GLOBALS['roles']['editor']['capabilities']['edit_files'] = true;
$GLOBALS['roles']['author']['capabilities']['unfiltered_html'] = true;
$GLOBALS['roles']['shop-manager'] = ['capabilities' => ['install_plugins' => true, 'activate_plugins' => true]];
$row = CoreChecks::privilegedRoles();
assert($row['status'] === 'fail' && $row['value'] === 3, 'three roles beyond their defaults');
assert($row['detail']['role_editor'] === 'edit_files' && $row['detail']['role_author'] === 'unfiltered_html' && $row['detail']['role_shop_manager'] === 'install_plugins,activate_plugins', 'role slugs become detail keys: '.json_encode($row['detail']));
assert(! isset($row['detail']['role_administrator']), 'administrator is never listed');

for ($i = 1; $i <= 20; $i++) {
    $GLOBALS['roles']["custom_{$i}"] = ['capabilities' => ['edit_users' => true]];
}
$row = CoreChecks::privilegedRoles();
assert($row['value'] === 23 && $row['detail']['truncated'] === true && count($row['detail']) === 2 + CoreChecks::MAX_ROLES, 'role list bounded to MAX_ROLES entries, count stays honest');

// --- Hidden administrators -----------------------------------------------------
$GLOBALS['admins'] = [1, 2];
$GLOBALS['wpdb']->adminMetaCount = '2';
$row = CoreChecks::hiddenAdmins();
assert($row['status'] === 'ok' && $row['value'] === 0 && $row['detail'] === ['meta_count' => 2, 'listed_count' => 2], 'meta and list agree');
assert(strpos($GLOBALS['wpdb']->log[0], "meta_key = 'wp_capabilities'") !== false && strpos($GLOBALS['wpdb']->log[0], '%"administrator"%') !== false, 'capability meta is queried by prefix: '.$GLOBALS['wpdb']->log[0]);

$GLOBALS['wpdb']->adminMetaCount = '3';
$row = CoreChecks::hiddenAdmins();
assert($row['status'] === 'fail' && $row['value'] === 1 && $row['detail']['meta_count'] === 3, 'one administrator the list does not show');

$GLOBALS['wpdb']->adminMetaCount = null;
$row = CoreChecks::hiddenAdmins();
assert($row['status'] === 'unknown' && $row['message'] === 'msg.not_readable', 'unreadable meta → unknown');
$GLOBALS['wpdb']->adminMetaCount = '2';

// --- Application passwords -----------------------------------------------------
$row = CoreChecks::adminAppPasswords();
assert($row['status'] === 'ok' && $row['value'] === 0 && $row['detail'] === ['admins_with_passwords' => 0, 'passwords' => 0, 'available' => true], 'no application passwords');

$GLOBALS['app_passwords'] = [2 => [['uuid' => 'a', 'name' => 'zapier'], ['uuid' => 'b', 'name' => 'mobile']]];
$row = CoreChecks::adminAppPasswords();
assert($row['status'] === 'warn' && $row['value'] === 1 && $row['detail']['passwords'] === 2, 'one admin with two application passwords → warn');
assert(strpos(json_encode($row), 'zapier') === false, 'password names never leave');

// --- mu-plugins and drop-ins -------------------------------------------------------
$row = CoreChecks::muPlugins();
assert($row['status'] === 'ok' && $row['value'] === 0 && $row['detail']['dropins'] === '' && $row['detail']['dropin_count'] === 0, 'empty tree');

$GLOBALS['mu'] = ['zz-loader.php' => [], 'a-first.php' => [], '../evil.php' => []];
file_put_contents($root.'/wp-content/db.php', "<?php\n");
file_put_contents($root.'/wp-content/object-cache.php', "<?php\n");
$row = CoreChecks::muPlugins();
assert($row['value'] === 3 && $row['detail']['mu_plugins'] === 'a-first.php,evil.php,zz-loader.php' && $row['detail']['mu_truncated'] === false, 'mu-plugins as sorted basenames: '.json_encode($row['detail']));
assert($row['detail']['dropins'] === 'db.php,object-cache.php' && $row['detail']['dropin_count'] === 2, 'drop-ins present: '.$row['detail']['dropins']);

$GLOBALS['mu'] = [];
for ($i = 1; $i <= 40; $i++) {
    $GLOBALS['mu'][sprintf('plugin-with-a-long-name-%02d.php', $i)] = [];
}
$row = CoreChecks::muPlugins();
assert($row['value'] === 40 && $row['detail']['mu_truncated'] === true && strlen($row['detail']['mu_plugins']) <= CoreChecks::MAX_LIST_LENGTH, 'mu list bounded by the string cap, count honest');
$GLOBALS['mu'] = [];

// --- Debug posture -----------------------------------------------------------------
$row = CoreChecks::debugPosture();
assert($row['status'] === 'ok' && $row['detail']['environment_type'] === 'production' && $row['detail']['debug_log'] === false && $row['detail']['debug'] === false, 'no constants → clean');

$GLOBALS['environment'] = 'staging';
$row = CoreChecks::debugPosture();
assert($row['status'] === 'ok' && $row['value'] === 1 && $row['detail']['environment_type'] === 'staging', 'non-production environment is information');
$GLOBALS['environment'] = 'production';

// --- Cron lag ---------------------------------------------------------------------
$GLOBALS['cron'] = [time() + 600 => ['wp_version_check' => ['abc' => ['schedule' => 'twicedaily', 'args' => []]]], 'version' => 2];
$row = CoreChecks::cronLag();
assert($row['status'] === 'ok' && $row['value'] === 0 && $row['detail']['overdue_events'] === 0 && $row['detail']['scheduled_events'] === 1 && $row['detail']['disabled'] === false, 'nothing overdue: '.json_encode($row));

$GLOBALS['cron'] = [
    time() - 7 * 3600 => ['wp_update_plugins' => ['a' => []], 'publish_future_post' => ['b' => [], 'c' => []]],
    time() - 60 => ['wp_scheduled_delete' => ['d' => []]],
    time() + 60 => ['wp_version_check' => ['e' => []]],
];
$row = CoreChecks::cronLag();
assert($row['status'] === 'warn' && $row['value'] >= 7 * 3600 && $row['detail']['overdue_events'] === 4, 'seven hours behind → warn, overdue counted: '.json_encode($row['detail']));
assert(strpos(json_encode($row), 'publish_future_post') === false, 'hook names never leave');

$GLOBALS['cron'] = [time() - 2 * 86400 => ['wp_update_plugins' => ['a' => []]]];
assert(CoreChecks::cronLag()['status'] === 'fail', 'two days behind → fail');

$GLOBALS['cron'] = false;
$row = CoreChecks::cronLag();
assert($row['status'] === 'unknown' && $row['value'] === null, 'unreadable cron table → unknown');
$GLOBALS['cron'] = [];

// --- Hidden mode ---------------------------------------------------------------------
$GLOBALS['options'] = [];
$GLOBALS['plugins'] = [];
$GLOBALS['active'] = [];
$row = CoreChecks::hiddenMode();
assert($row['status'] === 'ok' && $row['detail'] === ['active' => false, 'source' => '', 'sources' => '', 'mode' => '', 'scope' => '', 'stale_maintenance_file' => false], 'nothing hides the site');

// Elementor: mode + template id, as its own is_enabled() reads them.
install('elementor/elementor.php');
$GLOBALS['options'] = ['elementor_maintenance_mode_mode' => 'coming_soon'];
assert(CoreChecks::hiddenMode()['status'] === 'ok', 'elementor mode without a template is not enabled');
$GLOBALS['options']['elementor_maintenance_mode_template_id'] = '12';
$row = CoreChecks::hiddenMode();
assert($row['status'] === 'fail' && $row['detail']['source'] === 'elementor' && $row['detail']['mode'] === 'coming_soon' && $row['detail']['scope'] === 'site', 'elementor coming soon: '.json_encode($row['detail']));

// An inactive Elementor with the options left behind hides nothing.
$GLOBALS['active'] = [];
assert(CoreChecks::hiddenMode()['status'] === 'ok', 'inactive plugin options are ignored');

// SeedProd: the JSON settings option.
$GLOBALS['options'] = ['seedprod_settings' => '{"api_key":"","enable_coming_soon_mode":false,"enable_maintenance_mode":true}'];
install('coming-soon/coming-soon.php');
$row = CoreChecks::hiddenMode();
assert($row['status'] === 'fail' && $row['detail']['source'] === 'coming-soon' && $row['detail']['mode'] === 'maintenance', 'seedprod maintenance mode: '.json_encode($row['detail']));
assert(strpos(json_encode($row), 'api_key') === false, 'seedprod settings never leave');

// WooCommerce: launch gate; store pages only → warn.
$GLOBALS['active'] = [];
$GLOBALS['options'] = ['woocommerce_coming_soon' => 'yes', 'woocommerce_store_pages_only' => 'yes'];
install('woocommerce/woocommerce.php');
$row = CoreChecks::hiddenMode();
assert($row['status'] === 'warn' && $row['detail']['source'] === 'woocommerce' && $row['detail']['scope'] === 'store', 'woocommerce store-only coming soon → warn');
$GLOBALS['options']['woocommerce_store_pages_only'] = 'no';
assert(CoreChecks::hiddenMode()['status'] === 'fail', 'woocommerce whole-site coming soon → fail');
$GLOBALS['options'] = ['woocommerce_coming_soon' => 'no'];
assert(CoreChecks::hiddenMode()['status'] === 'ok', 'live store');

// Core .maintenance: honoured for ten minutes, stale afterwards.
$GLOBALS['active'] = [];
file_put_contents($root.'/.maintenance', '<?php $upgrading = '.time().';');
$row = CoreChecks::hiddenMode();
assert($row['status'] === 'fail' && $row['detail']['source'] === 'core' && $row['detail']['mode'] === 'maintenance', 'fresh .maintenance file');
touch($root.'/.maintenance', time() - 3600);
clearstatcache();
$row = CoreChecks::hiddenMode();
assert($row['status'] === 'ok' && $row['detail']['stale_maintenance_file'] === true, 'stale .maintenance file is ignored like WordPress does');
unlink($root.'/.maintenance');

// Two sources at once: both named, the first decides the mode.
$GLOBALS['options'] = ['elementor_maintenance_mode_mode' => 'maintenance', 'elementor_maintenance_mode_template_id' => '3', 'woocommerce_coming_soon' => 'yes'];
$GLOBALS['active'] = ['elementor/elementor.php', 'woocommerce/woocommerce.php'];
$row = CoreChecks::hiddenMode();
assert($row['detail']['sources'] === 'elementor,woocommerce' && $row['detail']['mode'] === 'maintenance', 'several sources: '.json_encode($row['detail']));

// --- Backup plugin -----------------------------------------------------------------------
$GLOBALS['plugins'] = [];
$GLOBALS['active'] = [];
$row = CoreChecks::backupPlugin();
assert($row['status'] === 'ok' && $row['value'] === 0 && $row['detail'] === ['present' => false, 'slug' => '', 'count' => 0], 'no backup plugin');

install('updraftplus/updraftplus.php', false);
assert(CoreChecks::backupPlugin()['detail']['present'] === false, 'an inactive backup plugin does not count');
install('updraftplus/updraftplus.php');
install('backwpup/backwpup.php');
$row = CoreChecks::backupPlugin();
assert($row['status'] === 'ok' && $row['value'] === 2 && $row['detail']['present'] === true && $row['detail']['slug'] === 'updraftplus', 'active backup plugins counted, first slug reported');
assert(count(BackupPlugins::SLUGS) === count(array_unique(BackupPlugins::SLUGS)) && count(BackupPlugins::SLUGS) >= 20, 'slug list unique and at least twenty');

// --- Whole set: nine rows, every detail flat and bounded like the panel validator demands.
$rows = CoreChecks::all();
assert(count($rows) === 9, 'nine core rows');

foreach ($rows as $row) {
    assert(preg_match('/^wp_[a-z_]+$/', $row['id']) === 1 && in_array($row['status'], ['ok', 'warn', 'fail', 'unknown'], true), 'id and status shape');
    assert(count($row['detail']) <= 20, $row['id'].' detail within 20 entries');

    foreach ($row['detail'] as $key => $value) {
        assert(preg_match('/^[a-z0-9_]{1,64}$/', (string) $key) === 1, $row['id'].' detail key '.$key);
        assert(is_bool($value) || is_int($value) || (is_string($value) && strlen($value) <= 200), $row['id'].' detail value '.$key);
    }
}

fwrite(STDERR, "core ok\n");
