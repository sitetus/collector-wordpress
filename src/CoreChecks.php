<?php

namespace Sitetus\WP;

/**
 * The WordPress core slice (plugin 0.3.0; panel decision C/D1, 03.10.2026):
 * what the site's own settings, roles, cron table and file tree say about
 * registration, privileged roles, hidden administrators, application
 * passwords, must-use plugins and drop-ins, debug posture, cron lag, a
 * "hidden from visitors" mode and whether a backup plugin is active.
 *
 * Every row carries counts, booleans, role slugs and file basenames only —
 * never a user name, an e-mail address, a title, a URL or a path. The
 * status set here mirrors the panel's rule so a raw envelope reads
 * sensibly; the panel re-decides every row from `detail`.
 *
 * Ids are prefixed `wp_`; the panel maps them to collector.wp_*.
 */
final class CoreChecks
{
    /**
     * Capabilities that hand a role the keys to the install.
     *
     * @var array<int, string>
     */
    public const DANGEROUS_CAPABILITIES = [
        'unfiltered_html', 'edit_files', 'install_plugins', 'activate_plugins', 'edit_plugins',
        'edit_themes', 'update_core', 'manage_options', 'promote_users', 'delete_users', 'edit_users',
    ];

    /**
     * On a single site WordPress itself grants editors unfiltered_html
     * (verified on 7.1.2, 03.10.2026), and plugins clone the editor role
     * (Yoast's wpseo_editor / wpseo_manager carry it too). An editor-grade
     * role — one that may edit others' pages — therefore keeps
     * unfiltered_html as its default; on an author, contributor or custom
     * lesser role the same capability is a grant worth flagging.
     */
    public const EDITOR_GRADE_CAPABILITY = 'edit_others_pages';

    /**
     * @var array<int, string>
     */
    public const EDITOR_GRADE_DEFAULTS = ['unfiltered_html'];

    /**
     * Roles the registration row treats as privileged when they are the default.
     *
     * @var array<int, string>
     */
    public const PRIVILEGED_ROLES = ['administrator', 'editor', 'author', 'contributor'];

    /**
     * @var array<int, string>
     */
    public const DROPINS = ['db.php', 'object-cache.php', 'advanced-cache.php', 'db-error.php', 'maintenance.php', 'php-error.php', 'fatal-error-handler.php', 'sunrise.php', 'install.php'];

    public const MAX_ROLES = 15;

    public const MAX_MU_PLUGINS = 30;

    public const MAX_LIST_LENGTH = 200;

    public const MAX_ADMINS = 500;

    public const CRON_WARN_SECONDS = 21600;

    public const CRON_FAIL_SECONDS = 86400;

    /**
     * WordPress ignores a .maintenance file older than this (wp-settings.php).
     */
    public const MAINTENANCE_FILE_TTL = 600;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::registration(),
            self::privilegedRoles(),
            self::hiddenAdmins(),
            self::adminAppPasswords(),
            self::muPlugins(),
            self::debugPosture(),
            self::cronLag(),
            self::hiddenMode(),
            self::backupPlugin(),
        ];
    }

    /**
     * Site Health 7.0 `insecure_registration`, read directly so older
     * installs get it too: open registration with a privileged default role.
     *
     * @return array<string, mixed>
     */
    public static function registration(): array
    {
        $open = (bool) get_option('users_can_register');
        $role = self::slug((string) get_option('default_role', 'subscriber'), 64, 'subscriber');
        $privileged = $open && in_array($role, self::PRIVILEGED_ROLES, true);

        return [
            'id' => 'wp_registration',
            'status' => $privileged ? 'fail' : 'ok',
            'value' => $open ? 1 : 0,
            'detail' => ['open' => $open, 'default_role' => $role],
        ];
    }

    /**
     * Non-administrator roles that hold a dangerous capability, beyond what
     * WordPress grants them by default: {role_<slug>: "cap,cap"}.
     *
     * @return array<string, mixed>
     */
    public static function privilegedRoles(): array
    {
        $roles = function_exists('wp_roles') ? wp_roles()->roles : [];
        $detail = ['roles' => 0, 'truncated' => false];
        $flagged = 0;

        foreach (is_array($roles) ? $roles : [] as $role => $definition) {
            $slug = self::slug((string) $role, 40);

            if ($slug === null || $slug === 'administrator' || ! is_array($definition)) {
                continue;
            }

            $granted = [];
            $defaults = ! empty($definition['capabilities'][self::EDITOR_GRADE_CAPABILITY]) ? self::EDITOR_GRADE_DEFAULTS : [];

            foreach (self::DANGEROUS_CAPABILITIES as $capability) {
                if (! empty($definition['capabilities'][$capability]) && ! in_array($capability, $defaults, true)) {
                    $granted[] = $capability;
                }
            }

            if ($granted === []) {
                continue;
            }

            $flagged++;

            if ($flagged > self::MAX_ROLES) {
                $detail['truncated'] = true;

                continue;
            }

            $detail['role_'.str_replace('-', '_', $slug)] = implode(',', $granted);
        }

        $detail['roles'] = $flagged;

        return [
            'id' => 'wp_privileged_roles',
            'status' => $flagged > 0 ? 'fail' : 'ok',
            'value' => $flagged,
            'detail' => $detail,
        ];
    }

    /**
     * Administrators counted straight from the capability meta row versus
     * administrators the user query lists: a plugin that hides a user from
     * the list (pre_user_query) cannot hide the meta row.
     *
     * @return array<string, mixed>
     */
    public static function hiddenAdmins(): array
    {
        global $wpdb;

        $metaCount = null;

        if (is_object($wpdb) && method_exists($wpdb, 'prepare') && method_exists($wpdb, 'get_var')) {
            $prepared = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
                $wpdb->prefix.'capabilities',
                '%"administrator"%'
            );
            $value = is_string($prepared) ? $wpdb->get_var($prepared) : null;
            $metaCount = is_numeric($value) ? max(0, (int) $value) : null;
        }

        $listed = count(self::administratorIds());

        if ($metaCount === null) {
            return ['id' => 'wp_hidden_admins', 'status' => 'unknown', 'value' => null, 'message' => 'msg.not_readable', 'detail' => ['listed_count' => $listed]];
        }

        $hidden = max(0, $metaCount - $listed);

        return [
            'id' => 'wp_hidden_admins',
            'status' => $hidden > 0 ? 'fail' : 'ok',
            'value' => $hidden,
            'detail' => ['meta_count' => $metaCount, 'listed_count' => $listed],
        ];
    }

    /**
     * Administrators with at least one application password (count only):
     * Basic-Auth access that bypasses any 2FA plugin on the login form.
     *
     * @return array<string, mixed>
     */
    public static function adminAppPasswords(): array
    {
        $available = function_exists('wp_is_application_passwords_available') && wp_is_application_passwords_available();
        $admins = 0;
        $passwords = 0;

        if (class_exists('\WP_Application_Passwords')) {
            foreach (self::administratorIds() as $id) {
                $list = \WP_Application_Passwords::get_user_application_passwords($id);
                $count = is_array($list) ? count($list) : 0;

                if ($count > 0) {
                    $admins++;
                    $passwords += $count;
                }
            }
        }

        return [
            'id' => 'wp_admin_app_passwords',
            'status' => $admins > 0 ? 'warn' : 'ok',
            'value' => $admins,
            'detail' => ['admins_with_passwords' => $admins, 'passwords' => $passwords, 'available' => $available],
        ];
    }

    /**
     * Must-use plugins (file basenames) and the drop-ins present in
     * wp-content; both load without ever appearing as "active".
     *
     * @return array<string, mixed>
     */
    public static function muPlugins(): array
    {
        $names = [];

        if (function_exists('get_mu_plugins')) {
            foreach (array_keys((array) get_mu_plugins()) as $file) {
                $name = self::basename((string) $file);

                if ($name !== null) {
                    $names[] = $name;
                }
            }
        } elseif (defined('WPMU_PLUGIN_DIR') && is_dir(WPMU_PLUGIN_DIR)) {
            foreach ((array) glob(WPMU_PLUGIN_DIR.'/*.php') as $file) {
                $name = self::basename(basename((string) $file));

                if ($name !== null) {
                    $names[] = $name;
                }
            }
        }

        sort($names);
        $muCount = count($names);
        $names = array_slice($names, 0, self::MAX_MU_PLUGINS);

        $dropins = [];
        $contentDir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH.'wp-content';

        foreach (self::DROPINS as $dropin) {
            if (is_file($contentDir.'/'.$dropin)) {
                $dropins[] = $dropin;
            }
        }

        list($muList, $muTruncated) = self::joined($names);

        return [
            'id' => 'wp_mu_plugins',
            'status' => 'ok',
            'value' => $muCount,
            'detail' => [
                'mu_count' => $muCount,
                'mu_plugins' => $muList,
                'mu_truncated' => $muTruncated || $muCount > self::MAX_MU_PLUGINS,
                'dropin_count' => count($dropins),
                'dropins' => implode(',', $dropins),
            ],
        ];
    }

    /**
     * Debug and environment posture beyond wp_config_flags: WP_DEBUG_LOG
     * (only effective under WP_DEBUG — wp_debug_mode()), whether the log
     * would land under the web root (the path itself never leaves),
     * SCRIPT_DEBUG, SAVEQUERIES and the environment type.
     *
     * @return array<string, mixed>
     */
    public static function debugPosture(): array
    {
        $debug = defined('WP_DEBUG') && WP_DEBUG;
        $logSetting = defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : false;
        $debugLog = $logSetting === true || (is_string($logSetting) && $logSetting !== '');
        $inWebRoot = false;

        if ($logSetting === true) {
            $inWebRoot = true;
        } elseif ($debugLog) {
            $root = rtrim(str_replace('\\', '/', (string) realpath(ABSPATH)), '/');
            $directory = str_replace('\\', '/', (string) realpath(dirname((string) $logSetting)));
            $inWebRoot = $root !== '' && $directory !== '' && strpos($directory.'/', $root.'/') === 0;
        }

        $environment = function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'production';

        if (! in_array($environment, ['production', 'staging', 'development', 'local'], true)) {
            $environment = 'production';
        }

        $scriptDebug = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG;
        $saveQueries = defined('SAVEQUERIES') && SAVEQUERIES;
        $logging = $debug && $debugLog;
        $risky = ($logging ? 1 : 0) + ($environment !== 'production' ? 1 : 0) + ($scriptDebug ? 1 : 0) + ($saveQueries ? 1 : 0);

        return [
            'id' => 'wp_debug_posture',
            'status' => $environment === 'production' && $logging && $inWebRoot ? 'warn' : 'ok',
            'value' => $risky,
            'detail' => [
                'debug' => $debug,
                'debug_log' => $debugLog,
                'debug_log_in_web_root' => $inWebRoot,
                'environment_type' => $environment,
                'script_debug' => $scriptDebug,
                'save_queries' => $saveQueries,
            ],
        ];
    }

    /**
     * How far behind WP-Cron is: the oldest event whose time has passed
     * (seconds) and how many events are overdue; event names never leave.
     *
     * @return array<string, mixed>
     */
    public static function cronLag(): array
    {
        $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $alternate = defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON;
        $cron = function_exists('_get_cron_array') ? _get_cron_array() : false;

        if (! is_array($cron)) {
            return ['id' => 'wp_cron_lag', 'status' => 'unknown', 'value' => null, 'message' => 'msg.not_readable', 'detail' => ['disabled' => $disabled, 'alternate' => $alternate]];
        }

        $now = time();
        $oldest = null;
        $overdue = 0;
        $scheduled = 0;

        foreach ($cron as $timestamp => $hooks) {
            if (! is_int($timestamp) || ! is_array($hooks)) {
                continue;
            }

            $events = 0;

            foreach ($hooks as $instances) {
                $events += is_array($instances) ? count($instances) : 0;
            }

            $scheduled += $events;

            if ($timestamp < $now) {
                $overdue += $events;
                $oldest = $oldest === null ? $timestamp : min($oldest, $timestamp);
            }
        }

        $lag = $oldest === null ? 0 : $now - $oldest;

        return [
            'id' => 'wp_cron_lag',
            'status' => $lag > self::CRON_FAIL_SECONDS ? 'fail' : ($lag > self::CRON_WARN_SECONDS ? 'warn' : 'ok'),
            'value' => $lag,
            'unit' => 's',
            'detail' => [
                'overdue_seconds' => $lag,
                'overdue_events' => $overdue,
                'scheduled_events' => $scheduled,
                'disabled' => $disabled,
                'alternate' => $alternate,
            ],
        ];
    }

    /**
     * D1: a "coming soon" or maintenance mode that hides the site from
     * visitors while the page still answers 200 — Elementor, SeedProd,
     * WooCommerce's launch gate, or a core .maintenance file that is still
     * honoured. Option names verified on the real plugins (03.10.2026).
     *
     * @return array<string, mixed>
     */
    public static function hiddenMode(): array
    {
        $sources = [];
        $mode = '';
        $scope = '';
        $staleMaintenance = false;

        $maintenanceFile = ABSPATH.'.maintenance';

        if (is_file($maintenanceFile)) {
            clearstatcache(true, $maintenanceFile);
            $age = time() - (int) @filemtime($maintenanceFile);

            if ($age <= self::MAINTENANCE_FILE_TTL) {
                $sources[] = 'core';
                $mode = 'maintenance';
            } else {
                $staleMaintenance = true;
            }
        }

        if (self::pluginActive('elementor/elementor.php')) {
            $elementorMode = get_option('elementor_maintenance_mode_mode', '');
            $template = get_option('elementor_maintenance_mode_template_id', '');

            if (in_array($elementorMode, ['coming_soon', 'maintenance'], true) && ! empty($template)) {
                $sources[] = 'elementor';
                $mode = $mode === '' ? $elementorMode : $mode;
            }
        }

        if (self::pluginActive('coming-soon/coming-soon.php')) {
            $settings = self::jsonOption('seedprod_settings');

            if (! empty($settings['enable_coming_soon_mode']) || ! empty($settings['enable_maintenance_mode'])) {
                $sources[] = 'coming-soon';
                $mode = $mode === '' ? (! empty($settings['enable_maintenance_mode']) ? 'maintenance' : 'coming_soon') : $mode;
            }
        }

        if (self::pluginActive('woocommerce/woocommerce.php') && get_option('woocommerce_coming_soon') === 'yes') {
            $sources[] = 'woocommerce';
            $mode = $mode === '' ? 'coming_soon' : $mode;
            $scope = get_option('woocommerce_store_pages_only') === 'yes' ? 'store' : 'site';
        }

        $active = $sources !== [];

        if ($active && $scope === '') {
            $scope = 'site';
        }

        return [
            'id' => 'wp_hidden_mode',
            'status' => $active ? ($scope === 'store' ? 'warn' : 'fail') : 'ok',
            'value' => $active ? 1 : 0,
            'detail' => [
                'active' => $active,
                'source' => $sources[0] ?? '',
                'sources' => implode(',', $sources),
                'mode' => $mode,
                'scope' => $scope,
                'stale_maintenance_file' => $staleMaintenance,
            ],
        ];
    }

    /**
     * Decision 9: whether one of the common backup plugins is active —
     * information only; the hosting provider may back the site up instead.
     *
     * @return array<string, mixed>
     */
    public static function backupPlugin(): array
    {
        $found = [];

        if (function_exists('get_plugins') && function_exists('is_plugin_active')) {
            foreach (array_keys((array) get_plugins()) as $file) {
                $slug = strtolower(strpos($file, '/') !== false ? (string) strstr($file, '/', true) : (string) preg_replace('/\.php$/', '', $file));

                if (in_array($slug, BackupPlugins::SLUGS, true) && is_plugin_active($file)) {
                    $found[] = $slug;
                }
            }
        }

        return [
            'id' => 'wp_backup_plugin',
            'status' => 'ok',
            'value' => count($found),
            'detail' => ['present' => $found !== [], 'slug' => $found[0] ?? '', 'count' => count($found)],
        ];
    }

    /**
     * @return array<int, int>
     */
    private static function administratorIds(): array
    {
        if (! function_exists('get_users')) {
            return [];
        }

        $ids = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => self::MAX_ADMINS]);

        return array_values(array_map('intval', array_filter((array) $ids, 'is_numeric')));
    }

    private static function pluginActive(string $file): bool
    {
        return function_exists('is_plugin_active') && is_plugin_active($file);
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonOption(string $name): array
    {
        $value = get_option($name, '');

        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private static function slug(string $value, int $max, ?string $fallback = null): ?string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,'.($max - 1).'}$/', $value) === 1 ? $value : $fallback;
    }

    private static function basename(string $file): ?string
    {
        $name = basename($file);

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $name) === 1 ? $name : null;
    }

    /**
     * Joins names with commas under the string cap; drops the tail when it
     * would not fit and says so.
     *
     * @param array<int, string> $names
     * @return array{0: string, 1: bool}
     */
    private static function joined(array $names): array
    {
        $list = '';
        $truncated = false;

        foreach ($names as $name) {
            $candidate = $list === '' ? $name : $list.','.$name;

            if (strlen($candidate) > self::MAX_LIST_LENGTH) {
                $truncated = true;
                break;
            }

            $list = $candidate;
        }

        return [$list, $truncated];
    }
}
