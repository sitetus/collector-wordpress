<?php
/**
 * mail_delivery and cache_posture tests without WordPress (plugin 0.4.0):
 * a fake $wpdb that answers prepared read-only queries from a script,
 * stubbed options and plugin list, and a temporary wp-content tree for the
 * drop-ins and config files. Shapes follow what was verified on real
 * installs (WP Mail SMTP 4.10.0, FluentSMTP 2.4.1, LiteSpeed Cache 7.9.1,
 * WP Super Cache 3.1.4 — wordpress01, 03.10.2026). Run:
 *
 *   php -d zend.assertions=1 -d assert.exception=1 tests/mail_cache.php
 */

declare(strict_types=1);

$root = sys_get_temp_dir().'/sitetus-wp-mailcache-'.bin2hex(random_bytes(4));
mkdir($root.'/wp-content/w3tc-config', 0777, true);

define('ABSPATH', $root.'/');
define('WP_CONTENT_DIR', $root.'/wp-content');

$GLOBALS['options'] = [];
$GLOBALS['plugins'] = [];
$GLOBALS['active'] = [];

function get_option(string $name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function get_plugins(): array { return $GLOBALS['plugins']; }
function is_plugin_active(string $file): bool { return in_array($file, $GLOBALS['active'], true); }

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

use Sitetus\WP\CachePostureModule;
use Sitetus\WP\MailDeliveryModule;
use Sitetus\WP\Readers\Cache\CachePostureReader;
use Sitetus\WP\Readers\Mail\FluentSmtpReader;
use Sitetus\WP\Readers\Mail\WpMailSmtpReader;
use Sitetus\WP\Readers\ReadBudget;

function reset_world(): FakeWpdb
{
    $GLOBALS['options'] = [];
    $GLOBALS['plugins'] = [];
    $GLOBALS['active'] = [];
    $GLOBALS['wpdb'] = new FakeWpdb();

    foreach (['advanced-cache.php', 'object-cache.php', 'wp-cache-config.php', 'w3tc-config/master.php'] as $file) {
        if (is_file(WP_CONTENT_DIR.'/'.$file)) {
            unlink(WP_CONTENT_DIR.'/'.$file);
        }
    }

    return $GLOBALS['wpdb'];
}

function install(string $file, string $version, bool $active = true): void
{
    $GLOBALS['plugins'][$file] = ['Name' => $file, 'Version' => $version];

    if ($active) {
        $GLOBALS['active'][] = $file;
    }
}

$empty = ['transport' => null, 'provider_configured' => null, 'errors_7d' => null, 'sent_7d' => null, 'logging_enabled' => null];

// --- WP Mail SMTP ---------------------------------------------------------
$db = reset_world();
install('wp-mail-smtp/wp_mail_smtp.php', '4.10.0');
$GLOBALS['options']['wp_mail_smtp'] = ['mail' => ['from_email' => 'x@example.test', 'mailer' => 'mail', 'return_path' => true], 'smtp' => ['autotls' => true, 'auth' => true]];
$reader = new WpMailSmtpReader(new ReadBudget(2.0));
assert($reader->isActive() && $reader->version() === '4.10.0' && $reader->supports('4.10.0') && ! $reader->supports('2.9') && ! $reader->supports('5.0'), 'wp mail smtp activity, version, support window');
$read = $reader->read();
assert($read === ['transport' => 'php_mail', 'provider_configured' => false, 'errors_7d' => null, 'sent_7d' => null, 'logging_enabled' => false], 'default mailer = PHP mail, no debug table yet → null errors: '.json_encode($read));
assert(count($db->log) === 2 && strpos($db->log[0], "SHOW TABLES LIKE 'wp_wpmailsmtp_debug_events'") !== false, 'table existence is asked, nothing else: '.json_encode($db->log));

// SMTP host configured, debug table present with two errors this week; Pro log counts sent.
$GLOBALS['options']['wp_mail_smtp'] = ['mail' => ['mailer' => 'smtp'], 'smtp' => ['host' => 'smtp.example.test', 'port' => 587, 'auth' => true, 'user' => 'u', 'pass' => 'p'], 'logs' => ['enabled' => true]];
$db->answers = [
    ['/SHOW TABLES LIKE \'wp_wpmailsmtp_debug_events\'/', 'wp_wpmailsmtp_debug_events'],
    ['/SHOW TABLES LIKE \'wp_wpmailsmtp_emails_log\'/', 'wp_wpmailsmtp_emails_log'],
    ['/FROM wp_wpmailsmtp_debug_events WHERE event_type = 0 AND created_at >= DATE_SUB\(NOW\(\), INTERVAL 7 DAY\)/', '2'],
    ['/FROM wp_wpmailsmtp_emails_log WHERE status = 1 AND date_sent >= DATE_SUB/', '120'],
];
$read = (new WpMailSmtpReader(new ReadBudget(2.0)))->read();
assert($read === ['transport' => 'smtp', 'provider_configured' => true, 'errors_7d' => 2, 'sent_7d' => 120, 'logging_enabled' => true], 'smtp with errors and pro log: '.json_encode($read));
assert(strpos(json_encode($db->log), 'smtp.example.test') === false, 'no host name reaches a query');

// An API mailer whose group is still empty is selected but not configured.
$GLOBALS['options']['wp_mail_smtp'] = ['mail' => ['mailer' => 'sendgrid'], 'sendgrid' => ['api_key' => '', 'domain' => '']];
$read = (new WpMailSmtpReader(new ReadBudget(2.0)))->read();
assert($read['transport'] === 'api' && $read['provider_configured'] === false, 'api mailer without a key is not configured');

// The Pro build is the same reader.
$db = reset_world();
install('wp-mail-smtp-pro/wp_mail_smtp.php', '4.10.0');
assert((new WpMailSmtpReader(new ReadBudget(2.0)))->isActive(), 'pro build counts as active');

// --- FluentSMTP -----------------------------------------------------------
$db = reset_world();
install('fluent-smtp/fluent-smtp.php', '2.4.1');
$reader = new FluentSmtpReader(new ReadBudget(2.0));
assert($reader->supports('2.4.1') && ! $reader->supports('1.9') && ! $reader->supports('3.0'), 'fluent support window');
$db->answers = [
    ['/SHOW TABLES LIKE \'wp_fsmpt_email_logs\'/', 'wp_fsmpt_email_logs'],
    ['/status = \'failed\' AND created_at >= DATE_SUB\(NOW\(\), INTERVAL 7 DAY\)/', '0'],
    ['/status = \'sent\' AND created_at >= DATE_SUB/', '0'],
];
$read = $reader->read();
assert($read === ['transport' => 'php_mail', 'provider_configured' => false, 'errors_7d' => 0, 'sent_7d' => 0, 'logging_enabled' => true], 'fluent without a connection: PHP mail, logging on by default, empty log: '.json_encode($read));

$GLOBALS['options']['fluentmail-settings'] = [
    'connections' => ['abc123' => ['title' => 'Main', 'provider_settings' => ['provider' => 'ses', 'sender_email' => 'x@example.test', 'access_key' => 'k', 'secret_key' => 's']]],
    'mappings' => ['x@example.test' => 'abc123'],
    'misc' => ['log_emails' => 'yes', 'log_saved_interval_days' => '14', 'default_connection' => 'abc123'],
];
$db->answers[1] = ['/status = \'failed\' AND created_at >= DATE_SUB/', '3'];
$db->answers[2] = ['/status = \'sent\' AND created_at >= DATE_SUB/', '41'];
$read = (new FluentSmtpReader(new ReadBudget(2.0)))->read();
assert($read === ['transport' => 'api', 'provider_configured' => true, 'errors_7d' => 3, 'sent_7d' => 41, 'logging_enabled' => true], 'fluent api connection with log counts: '.json_encode($read));

$GLOBALS['options']['fluentmail-settings']['connections']['abc123']['provider_settings']['provider'] = 'smtp';
$GLOBALS['options']['fluentmail-settings']['misc']['log_emails'] = 'no';
$read = (new FluentSmtpReader(new ReadBudget(2.0)))->read();
assert($read['transport'] === 'smtp' && $read['logging_enabled'] === false && $read['errors_7d'] === null && $read['sent_7d'] === null, 'logging off → counts not claimed');

// --- Mail module orchestration -------------------------------------------
$db = reset_world();
$section = MailDeliveryModule::run();
assert($section === ['plugin' => null, 'transport' => 'php_mail', 'provider_configured' => false, 'errors_7d' => null, 'sent_7d' => null, 'logging_enabled' => null], 'no plugin → PHP mail from core: '.json_encode($section));

install('wp-mail-smtp/wp_mail_smtp.php', '4.10.0');
install('fluent-smtp/fluent-smtp.php', '2.4.1');
$GLOBALS['options']['wp_mail_smtp'] = ['mail' => ['mailer' => 'mail']];
$section = MailDeliveryModule::run();
assert($section['plugin'] === ['slug' => 'wp-mail-smtp', 'version' => '4.10.0', 'supported' => true] && $section['transport'] === 'php_mail', 'first active reader wins');
assert(array_keys($section) === ['plugin', 'transport', 'provider_configured', 'errors_7d', 'sent_7d', 'logging_enabled'], 'fixed shape: '.json_encode(array_keys($section)));

$db = reset_world();
install('wp-mail-smtp/wp_mail_smtp.php', '5.2.0');
$section = MailDeliveryModule::run();
assert($section['plugin'] === ['slug' => 'wp-mail-smtp', 'version' => '5.2.0', 'supported' => false] && $section['transport'] === null && $db->log === [], 'unknown version → nothing claimed, nothing queried');

$db = reset_world();
install('post-smtp/postman-smtp.php', '4.0.2');
$section = MailDeliveryModule::run();
assert($section['plugin'] === ['slug' => 'post-smtp', 'version' => '4.0.2', 'supported' => false] && $section['transport'] === null, 'a known plugin without a reader is reported, not guessed');

$db = reset_world();
install('wp-mail-smtp/wp_mail_smtp.php', '4.10.0');
$section = MailDeliveryModule::run(null, new ReadBudget(-1.0));
assert($section['transport'] === 'php_mail' && $section['errors_7d'] === null && $db->log === [], 'spent budget → null counts, no queries');

// --- Cache posture ---------------------------------------------------------
reset_world();
$section = CachePostureModule::run();
assert($section === ['page_cache_plugins' => [], 'page_cache_enabled' => null, 'advanced_cache_dropin_owner' => null, 'object_cache_dropin' => false, 'multiple_page_caches' => false], 'bare site: '.json_encode($section));

// LiteSpeed alone, switch on, no drop-in (LiteSpeed does not write one).
install('litespeed-cache/litespeed-cache.php', '7.9.1');
install('autoptimize/autoptimize.php', '3.1.13');
$GLOBALS['options']['litespeed.conf.cache'] = '1';
$section = CachePostureModule::run();
assert($section['page_cache_plugins'] === ['litespeed-cache'] && $section['page_cache_enabled'] === true && $section['multiple_page_caches'] === false, 'litespeed on; autoptimize is not a page cache: '.json_encode($section));

// WP Super Cache joins: two page caches, its drop-in, its switch still off (the shape seen right after activation).
install('wp-super-cache/wp-cache.php', '3.1.4');
file_put_contents(WP_CONTENT_DIR.'/advanced-cache.php', "<?php\n// WP SUPER CACHE 1.2\nfunction wpcache_broken_message() {}\n");
file_put_contents(WP_CONTENT_DIR.'/wp-cache-config.php', "<?php\n\$cache_enabled = false;\n\$super_cache_enabled = true;\n");
file_put_contents(WP_CONTENT_DIR.'/object-cache.php', "<?php\n");
$section = CachePostureModule::run();
assert($section['page_cache_plugins'] === ['litespeed-cache', 'wp-super-cache'] && $section['multiple_page_caches'] === true, 'two page caches in preference order');
assert($section['advanced_cache_dropin_owner'] === 'wp-super-cache' && $section['object_cache_dropin'] === true, 'drop-in owner by signature');
assert($section['page_cache_enabled'] === true, 'the leading plugin\'s switch is the one reported');

// WP Super Cache alone: the config file decides.
reset_world();
install('wp-super-cache/wp-cache.php', '3.1.4');
file_put_contents(WP_CONTENT_DIR.'/wp-cache-config.php', "<?php\n\$cache_enabled = false;\n");
assert(CachePostureModule::run()['page_cache_enabled'] === false, 'super cache config off');
file_put_contents(WP_CONTENT_DIR.'/wp-cache-config.php', "<?php\n\$cache_enabled = true;\n");
assert(CachePostureModule::run()['page_cache_enabled'] === true, 'super cache config on');

// W3 Total Cache's JSON config, WP Fastest Cache's JSON option, Hummingbird, WP-Optimize.
reset_world();
install('w3-total-cache/w3-total-cache.php', '2.8.13');
file_put_contents(WP_CONTENT_DIR.'/w3tc-config/master.php', '<?php exit; ?>'.json_encode(['pgcache.enabled' => true, 'minify.enabled' => false]));
file_put_contents(WP_CONTENT_DIR.'/advanced-cache.php', "<?php\n/**\n * File: advanced-cache.php\n *\n * W3 Total Cache advanced cache module.\n */\n");
$section = CachePostureModule::run();
assert($section['page_cache_enabled'] === true && $section['advanced_cache_dropin_owner'] === 'w3-total-cache', 'w3tc: '.json_encode($section));

reset_world();
install('wp-fastest-cache/wpFastestCache.php', '1.3.6');
$GLOBALS['options']['WpFastestCache'] = json_encode(['wpFastestCacheStatus' => 'on', 'wpFastestCacheMinifyCss' => 'on']);
assert(CachePostureModule::run()['page_cache_enabled'] === true, 'wpfc status on');
$GLOBALS['options']['WpFastestCache'] = json_encode(['wpFastestCacheMinifyCss' => 'on']);
assert(CachePostureModule::run()['page_cache_enabled'] === false, 'wpfc status absent = off');

reset_world();
install('hummingbird-performance/wp-hummingbird.php', '3.19.0');
$GLOBALS['options']['wphb_settings'] = ['page_cache' => ['enabled' => false]];
file_put_contents(WP_CONTENT_DIR.'/advanced-cache.php', "<?php\n// WPHB_ADVANCED_CACHE\n");
$section = CachePostureModule::run();
assert($section['page_cache_enabled'] === false && $section['advanced_cache_dropin_owner'] === 'hummingbird-performance', 'hummingbird: '.json_encode($section));

reset_world();
install('wp-optimize/wp-optimize.php', '4.3.0');
$GLOBALS['options']['wpo_cache_config'] = ['enable_page_caching' => true];
assert(CachePostureModule::run()['page_cache_enabled'] === true, 'wp-optimize enable_page_caching');

// A drop-in left by a plugin that is no longer active: the owner is named, the plugin is not listed.
reset_world();
file_put_contents(WP_CONTENT_DIR.'/advanced-cache.php', "<?php\ndefine('WP_ROCKET_ADVANCED_CACHE', true);\n");
$section = CachePostureModule::run();
assert($section['page_cache_plugins'] === [] && $section['advanced_cache_dropin_owner'] === 'wp-rocket', 'stale drop-in owner');

// A plugin whose switch this reader does not know: listed, enabled null.
reset_world();
install('breeze/breeze.php', '2.6.0');
$section = CachePostureModule::run();
assert($section['page_cache_plugins'] === ['breeze'] && $section['page_cache_enabled'] === null, 'unknown switch → null');

assert(count(CachePostureReader::PAGE_CACHE_PLUGINS) === count(array_unique(CachePostureReader::PAGE_CACHE_PLUGINS)), 'slug list unique');

fwrite(STDERR, "mail_cache ok\n");
