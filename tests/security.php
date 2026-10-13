<?php
/**
 * Security reader tests without WordPress: a fake $wpdb that answers
 * prepared read-only queries from a script, stubbed options, plugin list,
 * administrator list and the Kadence module API, and the six readers plus
 * the module orchestration asserted against the storage shapes verified
 * on real installs (Wordfence 9.0.2, Kadence Security 10.0.5,
 * All-In-One Security 5.4.10, Really Simple Security 9.8.3, Limit Login
 * Attempts Reloaded 3.3.10, Two Factor 0.17.0 — wordpress01, 03.10.2026).
 * Run:
 *
 *   php -d zend.assertions=1 -d assert.exception=1 tests/security.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

$GLOBALS['options'] = [];
$GLOBALS['plugins'] = [];
$GLOBALS['active'] = [];
$GLOBALS['admins'] = [1, 2, 3];
$GLOBALS['itsec_active'] = null;
$GLOBALS['itsec_settings'] = [];

function get_option(string $name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function get_plugins(): array { return $GLOBALS['plugins']; }
function is_plugin_active(string $file): bool { return in_array($file, $GLOBALS['active'], true); }
function get_users(array $args): array { return $GLOBALS['admins']; }

/**
 * Kadence's module API as the reader sees it when the plugin is loaded;
 * a test sets $GLOBALS['itsec_active'] to null to exercise the option path.
 */
final class ITSEC_Modules
{
    public static function get_active_modules(): array { return $GLOBALS['itsec_active'] ?? []; }
    public static function get_setting(string $module, string $key) { return $GLOBALS['itsec_settings'][$module][$key] ?? null; }
}

/**
 * Prepared-query stand-in: %s/%d are filled like $wpdb->prepare does, and
 * every query the readers may send is answered from a list of regex
 * patterns. Every prepared statement is recorded so a test can assert that
 * nothing but SELECT/SHOW left the reader.
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';

    /** @var array<int, array{0: string, 1: mixed}> */
    public array $answers = [];

    /** @var array<int, string> */
    public array $log = [];

    public function prepare(string $query, ...$args): string
    {
        $query = str_replace("'%s'", '%s', $query);
        $i = 0;

        return (string) preg_replace_callback('/%[sdf]/', function (array $match) use (&$i, $args): string {
            $value = $args[$i++] ?? '';

            return $match[0] === '%s' ? "'".str_replace("'", "\\'", (string) $value)."'" : (string) $value;
        }, $query);
    }

    public function get_var(string $query)
    {
        return $this->answer($query);
    }

    public function get_results(string $query, string $output = 'OBJECT')
    {
        return $this->answer($query);
    }

    private function answer(string $query)
    {
        $this->log[] = $query;
        assert(preg_match('/^\s*(SELECT|SHOW)\b/i', $query) === 1, 'read-only query: '.$query);

        foreach ($this->answers as [$pattern, $value]) {
            if (preg_match($pattern, $query) === 1) {
                return $value;
            }
        }

        return null;
    }
}

require dirname(__DIR__).'/includes/autoload.php';

use Sitetus\WP\Readers\ReadBudget;
use Sitetus\WP\Readers\Security\AiosReader;
use Sitetus\WP\Readers\Security\KadenceSecurityReader;
use Sitetus\WP\Readers\Security\LimitLoginAttemptsReader;
use Sitetus\WP\Readers\Security\ReallySimpleSecurityReader;
use Sitetus\WP\Readers\Security\TwoFactorReader;
use Sitetus\WP\Readers\Security\WordfenceReader;
use Sitetus\WP\SecurityPluginModule;

function reset_world(): FakeWpdb
{
    $GLOBALS['options'] = [];
    $GLOBALS['plugins'] = [];
    $GLOBALS['active'] = [];
    $GLOBALS['admins'] = [1, 2, 3];
    $GLOBALS['itsec_active'] = null;
    $GLOBALS['itsec_settings'] = [];
    $GLOBALS['wpdb'] = new FakeWpdb();

    return $GLOBALS['wpdb'];
}

function install(string $file, string $version, bool $active = true): void
{
    $GLOBALS['plugins'][$file] = ['Name' => $file, 'Version' => $version];

    if ($active) {
        $GLOBALS['active'][] = $file;
    }
}

function pairs(array $values): array
{
    $rows = [];

    foreach ($values as $name => $val) {
        $rows[] = ['name' => $name, 'val' => $val];
    }

    return $rows;
}

// --- Wordfence -----------------------------------------------------------
$db = reset_world();
install('wordfence/wordfence.php', '9.0.2');
$db->answers = [
    ['/SHOW TABLES LIKE \'wp_wfconfig\'/', 'wp_wfconfig'],
    ['/SHOW TABLES LIKE \'wp_wfissues\'/', 'wp_wfissues'],
    ['/SHOW TABLES LIKE \'wp_wfls_2fa_secrets\'/', 'wp_wfls_2fa_secrets'],
    ['/SHOW TABLES LIKE \'wp_wfls_settings\'/', 'wp_wfls_settings'],
    ['/SELECT name, val FROM wp_wfconfig WHERE name IN/', pairs(['scanTime' => (string) (time() - 10 * 3600), 'lastScanCompleted' => 'ok', 'waf_status' => 'learning-mode', 'firewallEnabled' => '1', 'loginSecurityEnabled' => '1', 'loginSec_requireAdminTwoFactor' => '0', 'loginSec_disableAuthorScan' => '1', 'disableCodeExecutionUploads' => '0'])],
    ['/FROM wp_wfissues WHERE status = \'new\' AND severity >= 75 AND type NOT IN \(\'wfPluginVulnerable\'/', '2'],
    ['/FROM wp_wfissues WHERE status = \'new\' AND type NOT IN/', '5'],
    ['/FROM wp_wfls_2fa_secrets WHERE user_id IN \(1, 2, 3\)/', '1'],
    ['/FROM wp_wfls_settings WHERE name IN \(\'require-2fa.administrator\', \'xmlrpc-enabled\'\)/', pairs(['require-2fa.administrator' => '', 'xmlrpc-enabled' => '1'])],
    ['/FROM wp_wfls_settings WHERE name = \'xmlrpc-enabled\'/', pairs(['xmlrpc-enabled' => '1'])],
];
$wordfence = new WordfenceReader(new ReadBudget(2.0));
assert($wordfence->isActive() && $wordfence->version() === '9.0.2' && $wordfence->supports('9.0.2') && ! $wordfence->supports('7.4.9') && ! $wordfence->supports('10.0'), 'wordfence activity, version, support window');
$read = $wordfence->read();
assert($read['scan'] === ['open_critical' => 2, 'open_total' => 5, 'last_scan_age_hours' => 10, 'last_scan_failed' => false], 'wordfence scan from wfissues + wfconfig: '.json_encode($read['scan']));
assert($read['firewall'] === ['mode' => 'learning', 'extended_protection' => null], 'wordfence WAF learning mode from wfconfig waf_status');
assert($read['two_factor'] === ['admins_total' => 3, 'admins_enrolled' => 1, 'enforced_for_admins' => false], 'wordfence 2FA from wfls tables: '.json_encode($read['two_factor']));
assert($read['hardening'] === ['xmlrpc_disabled' => false, 'user_enumeration_blocked' => true, 'version_hidden' => null, 'file_editing_disabled' => null, 'login_url_changed' => null, 'uploads_php_blocked' => false], 'wordfence hardening flags: '.json_encode($read['hardening']));
assert($read['login_pressure'] === null && $read['roles'] === ['firewall' => true, 'login_limiter' => true], 'wordfence: no login counters, firewall + limiter roles');
assert(count(array_filter($db->log, fn (string $q): bool => stripos($q, 'wflogins') !== false || stripos($q, 'wfhits') !== false || stripos($q, 'longMsg') !== false)) === 0, 'wordfence: no log table or message column is ever queried');

// Before the first scan nothing is claimed; a failed scan and a disabled WAF are reported as such.
$db->answers[4] = ['/SELECT name, val FROM wp_wfconfig WHERE name IN/', pairs(['waf_status' => 'disabled', 'lastScanCompleted' => 'Scan time limit exceeded', 'scanTime' => (string) (time() - 200 * 3600), 'loginSecurityEnabled' => '0'])];
$read = (new WordfenceReader(new ReadBudget(2.0)))->read();
assert($read['scan']['last_scan_failed'] === true && $read['scan']['last_scan_age_hours'] === 200 && $read['firewall']['mode'] === 'disabled' && $read['roles'] === ['firewall' => false, 'login_limiter' => false], 'wordfence: failed scan, disabled WAF, nothing switched on');
$db->answers = [];
$read = (new WordfenceReader(new ReadBudget(2.0)))->read();
assert($read['scan'] === ['open_critical' => null, 'open_total' => null, 'last_scan_age_hours' => null, 'last_scan_failed' => null] && $read['firewall']['mode'] === null && $read['two_factor']['admins_enrolled'] === null, 'wordfence: no tables → null facts, not zeros');

// --- Kadence Security ----------------------------------------------------
$db = reset_world();
install('better-wp-security/better-wp-security.php', '10.0.5');
$GLOBALS['itsec_active'] = ['ban-users', 'firewall', 'brute-force', 'network-brute-force', 'backup', 'two-factor', 'file-change', 'wordpress-tweaks', 'hide-backend'];
$GLOBALS['itsec_settings'] = [
    'file-change' => ['last_scan' => 4711],
    'two-factor' => ['exclude_group' => []],
    'wordpress-tweaks' => ['disable_xmlrpc' => 'disable', 'file_editor' => true, 'rest_api' => 'default-access', 'disable_unused_author_pages' => false],
    'hide-backend' => ['enabled' => true],
    'system-tweaks' => ['uploads_php' => true],
];
$db->answers = [
    ['/meta_key = \'_two_factor_enabled_providers\' AND meta_value NOT IN \(\'\', \'a:0:\{\}\'\) AND user_id IN \(1, 2, 3\)/', '2'],
];
$kadence = new KadenceSecurityReader(new ReadBudget(2.0));
assert($kadence->version() === '10.0.5' && $kadence->supports('10.0.5') && ! $kadence->supports('7.9') && ! $kadence->supports('11.0'), 'kadence version from the plugin header, support window');
$read = $kadence->read();
assert($read['scan'] === ['open_critical' => null, 'open_total' => 1, 'last_scan_age_hours' => null, 'last_scan_failed' => null], 'kadence: file-change last_scan is a log id, so only "found changes" is claimed');
assert($read['firewall'] === ['mode' => 'enabled', 'extended_protection' => null], 'kadence firewall module active');
assert($read['two_factor'] === ['admins_total' => 3, 'admins_enrolled' => 2, 'enforced_for_admins' => true], 'kadence 2FA: shared meta key, empty exclude_group = on-boarding enforced: '.json_encode($read['two_factor']));
assert($read['hardening'] === ['xmlrpc_disabled' => true, 'user_enumeration_blocked' => false, 'version_hidden' => null, 'file_editing_disabled' => true, 'login_url_changed' => true, 'uploads_php_blocked' => false], 'kadence hardening: system-tweaks inactive → uploads not blocked: '.json_encode($read['hardening']));
assert($read['roles'] === ['firewall' => true, 'login_limiter' => true], 'kadence roles from the module list');

// A fresh install (no two-factor, no tweaks, firewall module off): inactive modules do nothing,
// even when their settings carry a value.
$GLOBALS['itsec_active'] = ['ban-users', 'brute-force'];
$GLOBALS['itsec_settings'] = ['wordpress-tweaks' => ['disable_xmlrpc' => 'disable']];
$read = (new KadenceSecurityReader(new ReadBudget(2.0)))->read();
assert($read['scan'] === null && $read['two_factor'] === null && $read['firewall']['mode'] === 'disabled' && $read['hardening']['xmlrpc_disabled'] === false && $read['roles'] === ['firewall' => false, 'login_limiter' => true], 'kadence fresh install: inactive modules do nothing');

// --- All-In-One Security -------------------------------------------------
$db = reset_world();
install('all-in-one-wp-security-and-firewall/wp-security.php', '5.4.10');
$GLOBALS['options']['aio_wp_security_configs'] = [
    'aiowps_enable_basic_firewall' => '1', 'aiowps_enable_6g_firewall' => '', 'aiowps_enable_pingback_firewall' => '0',
    'aiowps_enable_login_lockdown' => '1', 'aiowps_prevent_users_enumeration' => '1', 'aiowps_remove_wp_generator_meta_info' => '',
    'aiowps_disable_file_editing' => '1', 'aiowps_enable_rename_login_page' => '', 'aiowps_enable_automated_fcd_scan' => '1', 'aiowps_fcds_change_detected' => true,
];
$GLOBALS['options']['tfa_required_administrator'] = '1';
$db->answers = [
    ['/meta_key = \'tfa_enable_tfa\' AND meta_value NOT IN \(\'\', \'0\', \'false\'\) AND user_id IN \(1, 2, 3\)/', '3'],
];
$aios = new AiosReader(new ReadBudget(2.0));
assert($aios->supports('5.4.10') && ! $aios->supports('4.4.11') && ! $aios->supports('6.0'), 'aios support window');
$read = $aios->read();
assert($read['scan'] === ['open_critical' => null, 'open_total' => 1, 'last_scan_age_hours' => null, 'last_scan_failed' => null], 'aios: pending file change is the one open item');
assert($read['firewall'] === ['mode' => 'enabled', 'extended_protection' => null], 'aios basic firewall on');
assert($read['two_factor'] === ['admins_total' => 3, 'admins_enrolled' => 3, 'enforced_for_admins' => true], 'aios TFA from usermeta and tfa_required_administrator');
assert($read['hardening'] === ['xmlrpc_disabled' => false, 'user_enumeration_blocked' => true, 'version_hidden' => false, 'file_editing_disabled' => true, 'login_url_changed' => false, 'uploads_php_blocked' => null], 'aios hardening flags: '.json_encode($read['hardening']));
assert($read['roles'] === ['firewall' => true, 'login_limiter' => true], 'aios roles');
$GLOBALS['options']['aio_wp_security_configs'] = [];
assert((new AiosReader(new ReadBudget(2.0)))->read()['hardening'] === null, 'aios: no config yet → nothing claimed');

// --- Really Simple Security ----------------------------------------------
$db = reset_world();
install('really-simple-ssl/rlrsssl-really-simple-ssl.php', '9.8.3');
$GLOBALS['options']['rsssl_options'] = ['disable_xmlrpc' => 1, 'site_has_ssl' => '1', 'disable_user_enumeration' => 1, 'hide_wordpress_version' => 1, 'disable_file_editing' => 0, 'block_code_execution_uploads' => 1, 'enable_limited_login_attempts' => 1, 'login_protection_enabled' => 1, 'two_fa_forced_roles' => ['administrator'], 'recaptcha_secret_key' => 'never-read'];
$db->answers = [
    ['/meta_key IN \(\'rsssl_two_fa_status_totp\', \'rsssl_two_fa_status_email\', \'rsssl_two_fa_status_passkey\'\) AND meta_value = \'active\' AND user_id IN \(1, 2, 3\)/', '1'],
];
$rss = new ReallySimpleSecurityReader(new ReadBudget(2.0));
assert($rss->supports('9.8.3') && ! $rss->supports('6.2') && ! $rss->supports('10.0'), 'really simple security support window');
$read = $rss->read();
assert($read['firewall'] === null && $read['scan'] === null, 'really simple security free: no WAF, scanner results not read');
assert($read['two_factor'] === ['admins_total' => 3, 'admins_enrolled' => 1, 'enforced_for_admins' => true], 'really simple security 2FA: status meta + forced roles: '.json_encode($read['two_factor']));
assert($read['hardening'] === ['xmlrpc_disabled' => true, 'user_enumeration_blocked' => true, 'version_hidden' => true, 'file_editing_disabled' => false, 'login_url_changed' => false, 'uploads_php_blocked' => true], 'really simple security hardening: absent key = off: '.json_encode($read['hardening']));
assert($read['roles'] === ['firewall' => false, 'login_limiter' => true], 'really simple security roles');
assert(count(array_filter($db->log, fn (string $q): bool => stripos($q, 'secret') !== false)) === 0, 'no secret ever appears in a query');
$GLOBALS['options']['rsssl_options']['login_protection_enabled'] = 0;
assert((new ReallySimpleSecurityReader(new ReadBudget(2.0)))->read()['two_factor'] === null, 'really simple security: 2FA off → block left to another plugin');

// --- Limit Login Attempts Reloaded ---------------------------------------
$db = reset_world();
install('limit-login-attempts-reloaded/limit-login-attempts-reloaded.php', '3.3.10');
$hour = (int) floor(time() / 3600) * 3600;
$GLOBALS['options']['limit_login_retries_stats'] = [(string) $hour => 3, (string) ($hour - 5 * 3600) => 4, (string) ($hour - 30 * 3600) => 99];
$GLOBALS['options']['limit_login_lockouts_total'] = '7';
$GLOBALS['options']['limit_login_retries'] = ['203.0.113.9' => 3];
$llar = new LimitLoginAttemptsReader(new ReadBudget(2.0));
assert($llar->supports('3.3.10') && ! $llar->supports('2.19') && ! $llar->supports('4.0'), 'llar support window');
$read = $llar->read();
assert($read['login_pressure'] === ['failed_logins_24h' => 7, 'lockouts_total' => 7], 'llar: hourly buckets inside 24 h summed, old ones skipped: '.json_encode($read['login_pressure']));
assert($read['roles'] === ['firewall' => false, 'login_limiter' => true] && $read['scan'] === null && $read['hardening'] === null, 'llar is a limiter only');
assert($db->log === [], 'llar reads options only, never a table');
$GLOBALS['options']['limit_login_active_app'] = 'custom';
assert((new LimitLoginAttemptsReader(new ReadBudget(2.0)))->read()['login_pressure'] === ['failed_logins_24h' => null, 'lockouts_total' => null], 'llar cloud mode: local counters are empty, nothing claimed');

// --- Two Factor ----------------------------------------------------------
$db = reset_world();
install('two-factor/two-factor.php', '0.17.0');
$db->answers = [
    ['/meta_key = \'_two_factor_enabled_providers\' AND meta_value NOT IN \(\'\', \'a:0:\{\}\'\) AND user_id IN \(1, 2, 3\)/', '1'],
];
$twoFactor = new TwoFactorReader(new ReadBudget(2.0));
assert($twoFactor->supports('0.17.0') && ! $twoFactor->supports('0.4') && ! $twoFactor->supports('1.0'), 'two factor support window');
$read = $twoFactor->read();
assert($read['two_factor'] === ['admins_total' => 3, 'admins_enrolled' => 1, 'enforced_for_admins' => false], 'two factor: enabled-providers meta, no enforcement setting');
assert($read['roles'] === ['firewall' => false, 'login_limiter' => false], 'two factor has no firewall or limiter role');
assert(count(array_filter($db->log, fn (string $q): bool => stripos($q, 'totp') !== false || stripos($q, 'backup') !== false)) === 0, 'two factor: secrets and backup codes are never queried');

// --- Module orchestration ------------------------------------------------
$db = reset_world();
assert(SecurityPluginModule::run() === ['active_security_plugins' => ['count' => 0, 'slugs' => []], 'conflicts' => ['firewalls' => 0, 'login_limiters' => 0], 'unsupported' => 'no_security_plugin'], 'no security plugin → unsupported');

// Wordfence (learning WAF, limiter) + Two Factor + an unread Shield (static roles) + a premium add-on counted once.
install('wordfence/wordfence.php', '9.0.2');
install('wordfence-login-security/wordfence-login-security.php', '1.1.13');
install('two-factor/two-factor.php', '0.17.0');
install('wp-simple-firewall/icwp-wpsf.php', '20.0');
install('limit-login-attempts-reloaded/limit-login-attempts-reloaded.php', '3.3.10', false);
$db->answers = [
    ['/SHOW TABLES LIKE \'wp_wfconfig\'/', 'wp_wfconfig'],
    ['/SHOW TABLES LIKE \'wp_wfls_2fa_secrets\'/', 'wp_wfls_2fa_secrets'],
    ['/SELECT name, val FROM wp_wfconfig WHERE name IN/', pairs(['waf_status' => 'learning-mode', 'loginSecurityEnabled' => '1'])],
    ['/FROM wp_wfls_2fa_secrets WHERE user_id IN \(1, 2, 3\)/', '0'],
    ['/meta_key = \'_two_factor_enabled_providers\'/', '2'],
];
$section = SecurityPluginModule::run();
assert($section['active_security_plugins'] === ['count' => 3, 'slugs' => ['wordfence', 'two-factor', 'wp-simple-firewall']], 'families: add-on counts once, inactive plugin not at all: '.json_encode($section['active_security_plugins']));
assert($section['plugins'] === [['slug' => 'wordfence', 'version' => '9.0.2', 'supported' => true], ['slug' => 'two-factor', 'version' => '0.17.0', 'supported' => true]], 'readers that ran, in preference order');
assert($section['firewall'] === ['plugin' => 'wordfence', 'mode' => 'learning', 'extended_protection' => null], 'firewall block names its plugin');
assert($section['two_factor'] === ['plugin' => 'wordfence', 'admins_total' => 3, 'admins_enrolled' => 0, 'enforced_for_admins' => null], 'first reader in order keeps the 2FA block even when another plugin enrols more: '.json_encode($section['two_factor']));
assert($section['scan']['plugin'] === 'wordfence' && $section['login_pressure'] === null && $section['hardening']['plugin'] === 'wordfence', 'blocks nobody keeps stay null');
assert($section['conflicts'] === ['firewalls' => 2, 'login_limiters' => 2], 'conflicts: wordfence (read) + shield (static roles): '.json_encode($section['conflicts']));
assert(isset($section['duration_ms']) && $section['budget_exhausted'] === false && ! isset($section['unsupported']), 'budget reported, no unsupported marker');

// Every active reader outside its window → unsupported: version, nothing queried, conflicts from static roles.
$db = reset_world();
install('wordfence/wordfence.php', '10.0.0');
$section = SecurityPluginModule::run();
assert($section['unsupported'] === 'version' && $section['plugins'] === [['slug' => 'wordfence', 'version' => '10.0.0', 'supported' => false]] && $section['conflicts'] === ['firewalls' => 1, 'login_limiters' => 1], 'unknown version → unsupported: version: '.json_encode($section));
assert($db->log === [], 'nothing is queried for an unsupported version');

// A spent budget turns every count into null and says so.
$db = reset_world();
install('two-factor/two-factor.php', '0.17.0');
$section = SecurityPluginModule::run(null, new ReadBudget(-1.0));
assert($section['budget_exhausted'] === true && $section['two_factor']['admins_enrolled'] === null && $db->log === [], 'spent budget → null counts, no queries');

fwrite(STDERR, "security ok\n");
