<?php

namespace Sitetus\WP;

use Sitetus\WP\Readers\Cache\CachePostureReader;

/**
 * The opt-in `cache_posture` module (0.4.0): which page-cache plugins are
 * active at once, whether the leading one has its page cache switched on,
 * which plugin owns the advanced-cache.php drop-in and whether an
 * object-cache drop-in exists. Slugs and booleans only; the verdict
 * (conflict, disabled cache, stale drop-in) is the panel's.
 */
final class CachePostureModule
{
    public const ID = 'cache_posture';

    /**
     * @return array{page_cache_plugins: array<int, string>, page_cache_enabled: bool|null, advanced_cache_dropin_owner: string|null, object_cache_dropin: bool, multiple_page_caches: bool}
     */
    public static function run(?CachePostureReader $reader = null): array
    {
        $reader = $reader ?? new CachePostureReader();
        $plugins = $reader->activePageCachePlugins();

        return [
            'page_cache_plugins' => $plugins,
            'page_cache_enabled' => $plugins === [] ? null : $reader->pageCacheEnabled($plugins[0]),
            'advanced_cache_dropin_owner' => $reader->advancedCacheOwner(),
            'object_cache_dropin' => $reader->objectCacheDropin(),
            'multiple_page_caches' => count($plugins) > 1,
        ];
    }
}
