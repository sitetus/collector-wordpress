<?php

namespace Sitetus\WP;

/**
 * What only WordPress itself can tell: core / plugin / theme update state
 * (from WordPress' own update cache — this plugin never asks wordpress.org),
 * configuration flags as booleans, administrator count, auto-update
 * posture and the active theme. Names of people, e-mail addresses, salts,
 * database credentials and option values never leave the site. The core
 * slice (registration, roles, hidden admins, drop-ins, cron, hidden mode,
 * backup plugin) lives in CoreChecks and joins the same list.
 *
 * Check ids are prefixed `wp_`; the panel maps them to collector.wp_*.
 */
final class WordPressChecks
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        self::loadAdminFunctions();

        return array_merge([
            self::coreUpdate(),
            self::pluginUpdates(),
            self::themeUpdates(),
            self::configFlags(),
            self::adminAccounts(),
            self::autoUpdates(),
            self::themes(),
        ], CoreChecks::all());
    }

    /**
     * The installed plugins AND themes as the envelope's package inventory
     * (ecosystem `wordpress`; names are directory slugs; `kind` says which).
     *
     * @return array{ecosystem: string, source: string, lock_hash: string, items: array<int, array{name: string, version: string, kind: string, active: bool}>, dev_installed: bool}
     */
    public static function pluginInventory(): array
    {
        self::loadAdminFunctions();

        $items = [];
        $lines = [];

        foreach (self::plugins() as $file => $plugin) {
            $slug = self::slug($file);
            $version = isset($plugin['Version']) && is_scalar($plugin['Version']) ? trim((string) $plugin['Version']) : '';

            if ($slug === null) {
                continue;
            }

            $items[] = ['name' => $slug, 'version' => $version === '' ? '0' : substr($version, 0, 64), 'kind' => 'plugin', 'active' => is_plugin_active($file)];
            $lines[] = 'plugin|'.$slug.'|'.$version;
        }

        $activeTheme = (string) wp_get_theme()->get_stylesheet();

        foreach (function_exists('wp_get_themes') ? wp_get_themes() : [] as $stylesheet => $theme) {
            $slug = self::slug((string) $stylesheet);
            $version = $theme instanceof \WP_Theme ? trim((string) $theme->get('Version')) : '';

            if ($slug === null) {
                continue;
            }

            $items[] = ['name' => $slug, 'version' => $version === '' ? '0' : substr($version, 0, 64), 'kind' => 'theme', 'active' => (string) $stylesheet === $activeTheme];
            $lines[] = 'theme|'.$slug.'|'.$version;
        }

        sort($lines);

        return [
            'ecosystem' => 'wordpress',
            'source' => 'wordpress',
            'lock_hash' => hash('sha256', implode("\n", $lines)),
            'items' => $items,
            'dev_installed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function coreUpdate(): array
    {
        $installed = (string) get_bloginfo('version');
        $cache = get_site_transient('update_core');

        // WordPress' own cache is only evidence when it holds an answer: an empty or missing
        // cache (no admin visit, no cron) says nothing, so the status is unknown — the panel
        // compares the installed version with endoflife.date on its side anyway.
        if (! is_object($cache) || ! isset($cache->updates) || ! is_array($cache->updates) || $cache->updates === []) {
            return ['id' => 'wp_core_update', 'status' => 'unknown', 'value' => $installed, 'message' => 'msg.update_cache_missing'];
        }

        $latest = null;
        $sawAnswer = false;

        foreach ($cache->updates as $update) {
            if (! is_object($update) || ! isset($update->response)) {
                continue;
            }

            $sawAnswer = true;

            if ($update->response === 'upgrade' && isset($update->current)) {
                $latest = (string) $update->current;
                break;
            }
        }

        if (! $sawAnswer) {
            return ['id' => 'wp_core_update', 'status' => 'unknown', 'value' => $installed, 'message' => 'msg.update_cache_missing'];
        }

        if ($latest === null) {
            return ['id' => 'wp_core_update', 'status' => 'ok', 'value' => $installed];
        }

        // Only the numeric prefix matters; a different major or minor version means
        // known fixes are missing, a patch behind is a warning.
        $installedParts = explode('.', $installed);
        $latestParts = explode('.', $latest);
        $status = ($installedParts[0] ?? '') !== ($latestParts[0] ?? '') || ($installedParts[1] ?? '') !== ($latestParts[1] ?? '')
            ? 'fail'
            : 'warn';

        return ['id' => 'wp_core_update', 'status' => $status, 'value' => $installed, 'detail' => ['latest' => substr($latest, 0, 32)]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pluginUpdates(): array
    {
        $plugins = self::plugins();
        $cache = get_site_transient('update_plugins');

        if (! is_object($cache) || ! isset($cache->response)) {
            return ['id' => 'wp_plugin_updates', 'status' => 'unknown', 'value' => null, 'message' => 'msg.update_cache_missing'];
        }

        $pending = is_array($cache->response) ? count(array_intersect_key($cache->response, $plugins)) : 0;
        $active = 0;

        foreach (array_keys($plugins) as $file) {
            if (is_plugin_active($file)) {
                $active++;
            }
        }

        return [
            'id' => 'wp_plugin_updates',
            'status' => $pending === 0 ? 'ok' : ($pending >= 5 ? 'fail' : 'warn'),
            'value' => $pending,
            'detail' => ['installed' => count($plugins), 'active' => $active],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function themeUpdates(): array
    {
        $cache = get_site_transient('update_themes');

        if (! is_object($cache) || ! isset($cache->response)) {
            return ['id' => 'wp_theme_updates', 'status' => 'unknown', 'value' => null, 'message' => 'msg.update_cache_missing'];
        }

        $pending = is_array($cache->response) ? count($cache->response) : 0;

        return ['id' => 'wp_theme_updates', 'status' => $pending === 0 ? 'ok' : 'warn', 'value' => $pending];
    }

    /**
     * @return array<string, mixed>
     */
    private static function configFlags(): array
    {
        global $wpdb;

        $debug = defined('WP_DEBUG') && WP_DEBUG;
        $debugDisplay = $debug && (! defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY);
        $fileEditDisallowed = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT;
        $fileModsDisallowed = defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS;
        $forceSslAdmin = defined('FORCE_SSL_ADMIN') && FORCE_SSL_ADMIN;
        $autoUpdaterDisabled = defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED;
        $defaultPrefix = isset($wpdb->prefix) && $wpdb->prefix === 'wp_';
        $saltsDefault = self::saltsLookDefault();

        $risky = 0;
        $status = 'ok';

        if ($debugDisplay || $saltsDefault) {
            $status = 'fail';
            $risky += ($debugDisplay ? 1 : 0) + ($saltsDefault ? 1 : 0);
        }

        if ($debug && ! $debugDisplay) {
            $risky++;
        }

        if (! $fileEditDisallowed) {
            $risky++;
        }

        if ($status === 'ok' && $risky > 0) {
            $status = 'warn';
        }

        return [
            'id' => 'wp_config_flags',
            'status' => $status,
            'value' => $risky,
            'detail' => [
                'debug' => $debug,
                'debug_display' => $debugDisplay,
                'file_edit_disallowed' => $fileEditDisallowed,
                'file_mods_disallowed' => $fileModsDisallowed,
                'force_ssl_admin' => $forceSslAdmin,
                'auto_updater_disabled' => $autoUpdaterDisabled,
                'default_table_prefix' => $defaultPrefix,
                'salts_default' => $saltsDefault,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function adminAccounts(): array
    {
        $admins = get_users(['role' => 'administrator', 'fields' => ['ID', 'user_login'], 'number' => 200]);
        $count = count($admins);
        $hasAdminLogin = false;

        foreach ($admins as $user) {
            if (isset($user->user_login) && strtolower((string) $user->user_login) === 'admin') {
                $hasAdminLogin = true;
            }
        }

        $status = $hasAdminLogin ? 'warn' : ($count > 5 ? 'fail' : 'ok');

        return ['id' => 'wp_admin_accounts', 'status' => $status, 'value' => $count, 'detail' => ['admin_login_exists' => $hasAdminLogin]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function autoUpdates(): array
    {
        $updaterDisabled = defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED;
        $coreMinor = ! $updaterDisabled && (bool) apply_filters('allow_minor_auto_core_updates', true);
        $plugins = function_exists('wp_is_auto_update_enabled_for_type') && wp_is_auto_update_enabled_for_type('plugin');
        $themes = function_exists('wp_is_auto_update_enabled_for_type') && wp_is_auto_update_enabled_for_type('theme');

        return [
            'id' => 'wp_auto_updates',
            'status' => $updaterDisabled ? 'fail' : ($coreMinor ? 'ok' : 'warn'),
            'value' => $coreMinor ? 1 : 0,
            'detail' => ['core_minor' => $coreMinor, 'plugins' => $plugins, 'themes' => $themes],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function themes(): array
    {
        $theme = wp_get_theme();
        $parent = $theme->parent();
        $installed = function_exists('wp_get_themes') ? count(wp_get_themes()) : 0;

        return [
            'id' => 'wp_themes',
            'status' => 'ok',
            'value' => $installed,
            'detail' => [
                'active' => substr((string) $theme->get_stylesheet(), 0, 64),
                'version' => substr((string) $theme->get('Version'), 0, 32),
                'parent' => $parent instanceof \WP_Theme ? substr((string) $parent->get_stylesheet(), 0, 64) : '',
            ],
        ];
    }

    private static function saltsLookDefault(): bool
    {
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $constant) {
            if (! defined($constant)) {
                return true;
            }

            $value = (string) constant($constant);

            if ($value === '' || strlen($value) < 32 || stripos($value, 'put your unique phrase here') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function plugins(): array
    {
        $plugins = get_plugins();

        return is_array($plugins) ? $plugins : [];
    }

    private static function slug(string $file): ?string
    {
        $slug = strpos($file, '/') !== false ? strstr($file, '/', true) : preg_replace('/\.php$/', '', $file);
        $slug = strtolower((string) $slug);

        return preg_match('/^[a-z0-9][a-z0-9._-]{0,199}$/', $slug) === 1 ? $slug : null;
    }

    private static function loadAdminFunctions(): void
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
    }
}
