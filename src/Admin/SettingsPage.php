<?php

namespace Sitetus\WP\Admin;

use Sitetus\WP\NonceDirectory;
use Sitetus\WP\PairingCode;
use Sitetus\WP\Plugin;
use Sitetus\WP\Settings;

/**
 * Settings → Sitetus: one page. Not paired: a field for the pairing code
 * and the module choice. Paired: the connection facts, the module choice,
 * "Disconnect". Every action needs manage_options and a WordPress nonce;
 * nothing on this page talks to the network.
 */
final class SettingsPage
{
    public const SLUG = 'sitetus';

    public const CAPABILITY = 'manage_options';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_sitetus_pair', [self::class, 'pair']);
        add_action('admin_post_sitetus_modules', [self::class, 'modules']);
        add_action('admin_post_sitetus_unpair', [self::class, 'unpair']);
    }

    public static function menu(): void
    {
        add_options_page(
            __('Sitetus', 'sitetus'),
            __('Sitetus', 'sitetus'),
            self::CAPABILITY,
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage this page.', 'sitetus'));
        }

        $settings = Settings::get();
        $lastPull = Settings::lastPull();
        $notice = isset($_GET['sitetus']) ? sanitize_key((string) wp_unslash($_GET['sitetus'])) : '';
        $nonceDirWritable = NonceDirectory::ensure();
        $moduleLabels = self::moduleLabels();
        $coreVersion = Plugin::coreVersion();
        $endpoint = rest_url('sitetus/v1/collect');

        require SITETUS_PLUGIN_DIR.'/src/Admin/views/settings-page.php';
    }

    public static function pair(): void
    {
        self::guard('sitetus_pair');

        $code = isset($_POST['pairing_code']) ? (string) wp_unslash($_POST['pairing_code']) : '';
        $payload = PairingCode::decode($code);

        if ($payload === null) {
            self::back('invalid_code');
        }

        $modules = self::postedModules();

        Settings::pair($payload, $modules === null ? $payload['modules'] : $modules);
        NonceDirectory::ensure();

        self::back('paired');
    }

    public static function modules(): void
    {
        self::guard('sitetus_modules');

        Settings::updateModules(self::postedModules() ?? []);

        self::back('modules_saved');
    }

    public static function unpair(): void
    {
        self::guard('sitetus_unpair');

        Settings::unpair();

        self::back('unpaired');
    }

    /**
     * @return array<string, array{label: string, description: string}>
     */
    public static function moduleLabels(): array
    {
        return [
            'files' => [
                'label' => __('File trace', 'sitetus'),
                'description' => __('Sensitive file dates and sizes, executable files in upload folders, changed PHP files, web-shell signatures. File names only, never contents.', 'sitetus'),
            ],
            'archives' => [
                'label' => __('Archive scan', 'sitetus'),
                'description' => __('Forgotten backup archives and database dumps in the web root (names and sizes).', 'sitetus'),
            ],
            'permissions' => [
                'label' => __('Permissions', 'sitetus'),
                'description' => __('World-writable folders and files under the site root.', 'sitetus'),
            ],
            'bot_traffic' => [
                'label' => __('AI bot traffic', 'sitetus'),
                'description' => __('Counts per documented AI crawler from the newest access log (last 20 MB). No IP address, URL or user-agent string leaves the server.', 'sitetus'),
            ],
            'seo_plugin' => [
                'label' => __('SEO plugin records', 'sitetus'),
                'description' => __('Reads the active SEO plugin\'s own records (Yoast SEO, Rank Math, All in One SEO): how many published items are set to noindex per post type, post types closed to indexing, orphaned content, whether the plugin\'s sitemap and organization settings are filled. Counts and yes/no only; no title, URL or author leaves the server.', 'sitetus'),
            ],
            'mail_delivery' => [
                'label' => __('E-mail delivery', 'sitetus'),
                'description' => __('Reads how site e-mail leaves the server (PHP mail, SMTP or a provider API) from the active delivery plugin\'s settings (WP Mail SMTP, FluentSMTP) and counts sent and failed messages of the last seven days from its log. Transport class and counts only; no address, host, key or subject leaves the server.', 'sitetus'),
            ],
            'cache_posture' => [
                'label' => __('Cache posture', 'sitetus'),
                'description' => __('Which page-cache plugins are active at once, whether the leading one has its page cache switched on, and which plugin owns the advanced-cache.php and object-cache.php drop-ins. Plugin slugs and yes/no only.', 'sitetus'),
            ],
            'security_plugin' => [
                'label' => __('Security plugin records', 'sitetus'),
                'description' => __('Reads the active security plugins\' own records (Wordfence, Kadence Security, All-In-One Security, Really Simple Security, Limit Login Attempts Reloaded, Two Factor): open scanner findings and last scan age, firewall mode, how many administrators use two-factor, hardening switches, failed-login counters, and whether several firewalls or login limiters run at once. Counts, yes/no and mode names only; no IP address, user name, file path or key leaves the server.', 'sitetus'),
            ],
            'core_integrity' => [
                'label' => __('Core file integrity', 'sitetus'),
                'description' => __('Compares the WordPress core files with the official checksum list of the installed version: changed files, missing files and PHP files in wp-admin and wp-includes that are not part of the release. To get the list, this site asks the WordPress update server (api.wordpress.org) each time the module runs. The request carries the WordPress version and the language package and, like every request WordPress makes, the standard WordPress header with the version and the site address. The panel receives counts and file names only; no file contents leave the server and no file is repaired, replaced or deleted.', 'sitetus'),
            ],
        ];
    }

    private static function guard(string $action): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage this page.', 'sitetus'), '', ['response' => 403]);
        }

        check_admin_referer($action);
    }

    /**
     * @return array<int, string>|null null when the form carried no module field at all
     */
    private static function postedModules(): ?array
    {
        if (! isset($_POST['modules_present'])) {
            return null;
        }

        $posted = isset($_POST['modules']) && is_array($_POST['modules']) ? wp_unslash($_POST['modules']) : [];

        return array_values(array_filter(array_map('sanitize_key', $posted), static function (string $module): bool {
            return in_array($module, Settings::MODULES, true);
        }));
    }

    private static function back(string $notice): void
    {
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'sitetus' => $notice], admin_url('options-general.php')));
        exit;
    }
}
