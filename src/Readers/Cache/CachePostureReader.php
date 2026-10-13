<?php

namespace Sitetus\WP\Readers\Cache;

/**
 * What the cache_posture module reads from the site: which page-cache
 * plugins are active, whether the leading one has its page cache switched
 * on, which plugin wrote wp-content/advanced-cache.php, and whether an
 * object-cache drop-in is present. Options and the first kilobytes of two
 * files are read; nothing is executed or included.
 *
 * Page-cache switches verified on real installs (03.10.2026): LiteSpeed
 * Cache 7.9.1 option litespeed.conf.cache (1|''); WP Super Cache 3.1.4
 * $cache_enabled in wp-content/wp-cache-config.php (false right after
 * activation, true once caching is turned on); W3 Total Cache 2.8
 * pgcache.enabled in wp-content/w3tc-config/master.php ("<?php exit; ?>"
 * + JSON); WP Fastest Cache option WpFastestCache (JSON) key
 * wpFastestCacheStatus = "on"; WP-Optimize option wpo_cache_config
 * enable_page_caching; Hummingbird option wphb_settings
 * page_cache.enabled; Cache Enabler caches whenever active (option
 * cache_enabler exists). WP Rocket (not on wordpress.org) caches whenever
 * active. Other page-cache plugins are counted but their switch is not
 * read (null).
 */
final class CachePostureReader
{
    public const MAX_PLUGINS = 10;

    public const HEAD_BYTES = 4096;

    /**
     * Page-cache plugins by directory slug, in preference order: the first
     * active one is the one whose switch is reported.
     *
     * @var array<int, string>
     */
    public const PAGE_CACHE_PLUGINS = [
        'wp-rocket',
        'litespeed-cache',
        'wp-super-cache',
        'w3-total-cache',
        'wp-fastest-cache',
        'wp-optimize',
        'hummingbird-performance',
        'cache-enabler',
        'comet-cache',
        'breeze',
        'swift-performance-lite',
        'swift-performance',
        'powered-cache',
        'seraphinite-accelerator',
        'nitropack',
        'flying-press',
    ];

    /**
     * Strings a plugin leaves in the advanced-cache.php it writes, in the
     * order they are tried (the first match names the owner).
     *
     * @var array<string, string> needle => slug
     */
    public const DROPIN_SIGNATURES = [
        'WP SUPER CACHE' => 'wp-super-cache',
        'PgCache_ContentGrabber' => 'w3-total-cache',
        'W3 Total Cache' => 'w3-total-cache',
        'WP_ROCKET_ADVANCED_CACHE' => 'wp-rocket',
        'WP Rocket' => 'wp-rocket',
        'WPHB_ADVANCED_CACHE' => 'hummingbird-performance',
        'Hummingbird' => 'hummingbird-performance',
        'Cache Enabler' => 'cache-enabler',
        'WP-Optimize' => 'wp-optimize',
        'Comet Cache' => 'comet-cache',
        'Breeze' => 'breeze',
        'Swift Performance' => 'swift-performance-lite',
        'Powered Cache' => 'powered-cache',
        'FlyingPress' => 'flying-press',
        'LiteSpeed' => 'litespeed-cache',
    ];

    /**
     * Active page-cache plugins, in PAGE_CACHE_PLUGINS order, at most
     * MAX_PLUGINS.
     *
     * @return array<int, string>
     */
    public function activePageCachePlugins(): array
    {
        if (! function_exists('get_plugins') || ! function_exists('is_plugin_active')) {
            return [];
        }

        $active = [];

        foreach (array_keys((array) get_plugins()) as $file) {
            $slug = strtolower(strpos($file, '/') !== false ? (string) strstr($file, '/', true) : (string) preg_replace('/\.php$/', '', $file));

            if (in_array($slug, self::PAGE_CACHE_PLUGINS, true) && is_plugin_active($file)) {
                $active[$slug] = true;
            }
        }

        $ordered = array_values(array_filter(self::PAGE_CACHE_PLUGINS, static function (string $slug) use ($active): bool {
            return isset($active[$slug]);
        }));

        return array_slice($ordered, 0, self::MAX_PLUGINS);
    }

    /**
     * Whether the given plugin's page cache is switched on; null when this
     * reader does not know where that plugin keeps the switch.
     */
    public function pageCacheEnabled(string $slug): ?bool
    {
        switch ($slug) {
            case 'wp-rocket':
            case 'cache-enabler':
                return true;
            case 'litespeed-cache':
                return (bool) get_option('litespeed.conf.cache', false);
            case 'wp-super-cache':
                return $this->superCacheEnabled();
            case 'w3-total-cache':
                return $this->w3tcPageCacheEnabled();
            case 'wp-fastest-cache':
                $options = json_decode((string) get_option('WpFastestCache', ''), true);

                return is_array($options) && (($options['wpFastestCacheStatus'] ?? '') === 'on');
            case 'wp-optimize':
                $config = get_option('wpo_cache_config', []);

                return is_array($config) && (bool) ($config['enable_page_caching'] ?? false);
            case 'hummingbird-performance':
                $settings = get_option('wphb_settings', []);

                return is_array($settings) && (bool) (($settings['page_cache']['enabled'] ?? false));
            default:
                return null;
        }
    }

    /**
     * The plugin that wrote wp-content/advanced-cache.php, by the signature
     * in its first HEAD_BYTES; null when there is no drop-in or none of the
     * known plugins signed it.
     */
    public function advancedCacheOwner(): ?string
    {
        $head = $this->head($this->contentDir().'/advanced-cache.php');

        if ($head === null) {
            return null;
        }

        foreach (self::DROPIN_SIGNATURES as $needle => $slug) {
            if (stripos($head, $needle) !== false) {
                return $slug;
            }
        }

        return null;
    }

    public function objectCacheDropin(): bool
    {
        return is_file($this->contentDir().'/object-cache.php');
    }

    private function superCacheEnabled(): ?bool
    {
        $head = $this->head($this->contentDir().'/wp-cache-config.php');

        if ($head === null || preg_match('/^\s*\$cache_enabled\s*=\s*(true|false)\s*;/mi', $head, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]) === 'true';
    }

    private function w3tcPageCacheEnabled(): ?bool
    {
        $content = $this->head($this->contentDir().'/w3tc-config/master.php', 65536);

        if ($content === null) {
            return null;
        }

        $config = json_decode((string) substr($content, (int) strpos($content, '{')), true);

        return is_array($config) && array_key_exists('pgcache.enabled', $config) ? (bool) $config['pgcache.enabled'] : null;
    }

    private function head(string $path, int $bytes = self::HEAD_BYTES): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path, false, null, 0, $bytes);

        return is_string($content) ? $content : null;
    }

    private function contentDir(): string
    {
        return defined('WP_CONTENT_DIR') ? rtrim((string) WP_CONTENT_DIR, '/\\') : rtrim(ABSPATH, '/\\').'/wp-content';
    }
}
