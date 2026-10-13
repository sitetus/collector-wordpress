<?php

namespace Sitetus\WP;

use Sitetus\WP\Admin\SettingsPage;
use Sitetus\WP\Rest\CollectEndpoint;

/**
 * Wires the plugin into WordPress: the REST endpoint the panel calls, the
 * settings page where the owner pairs the site, translations, and the
 * settings link on the plugins list. Nothing runs on the front end and
 * nothing is scheduled — the panel comes to the site, never the reverse.
 */
final class Plugin
{
    public const OPTION = 'sitetus_collector';

    public const OPTION_LAST_PULL = 'sitetus_last_pull';

    public const TEXT_DOMAIN = 'sitetus';

    public static function boot(): void
    {
        add_action('init', [self::class, 'loadTextDomain']);
        add_action('rest_api_init', [CollectEndpoint::class, 'register']);

        if (is_admin()) {
            SettingsPage::register();
            add_filter('plugin_action_links_'.plugin_basename(SITETUS_PLUGIN_FILE), [self::class, 'actionLinks']);
        }
    }

    public static function loadTextDomain(): void
    {
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename(SITETUS_PLUGIN_FILE)).'/languages');
    }

    /**
     * @param array<int, string> $links
     * @return array<int, string>
     */
    public static function actionLinks(array $links): array
    {
        $url = admin_url('options-general.php?page='.SettingsPage::SLUG);

        array_unshift($links, '<a href="'.esc_url($url).'">'.esc_html__('Settings', 'sitetus').'</a>');

        return $links;
    }

    /**
     * The collector core version vendored into this plugin (includes/collector).
     */
    public static function coreVersion(): string
    {
        $path = SITETUS_PLUGIN_DIR.'/includes/collector/CORE_VERSION';

        return is_file($path) ? trim((string) file_get_contents($path)) : 'unknown';
    }
}
