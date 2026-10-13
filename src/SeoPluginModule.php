<?php

namespace Sitetus\WP;

use Sitetus\WP\Readers\AioseoReader;
use Sitetus\WP\Readers\PluginReader;
use Sitetus\WP\Readers\RankMathReader;
use Sitetus\WP\Readers\ReadBudget;
use Sitetus\WP\Readers\YoastReader;

/**
 * The opt-in `seo_plugin` module: reads the active SEO plugin's own records
 * through its reader and answers in one plugin-independent shape —
 * published and noindex counts per post type, post types closed to
 * indexing as a whole, orphaned content, whether the plugin's sitemap is
 * on, whether an organization is set, whether the plugin's index is
 * complete, and how many SEO plugins are active at once. Only counts,
 * booleans and post type names leave; never a title, URL or author.
 *
 * This module lives in the WordPress plugin, not in the shared collector
 * core, so it is always offered on the settings page (opt-in) regardless
 * of the pairing code's module list.
 */
final class SeoPluginModule
{
    public const ID = 'seo_plugin';

    public const BUDGET_SECONDS = 2.0;

    public const MAX_POST_TYPES = 20;

    public const MAX_POST_TYPE_LENGTH = 32;

    /**
     * Reader classes in preference order: when several SEO plugins are active
     * the first active one is read and the conflict is reported alongside.
     *
     * @var array<int, class-string<PluginReader>>
     */
    public const READERS = [YoastReader::class, RankMathReader::class, AioseoReader::class];

    /**
     * Plugin slugs that act as a site's SEO plugin, grouped by product so a
     * premium add-on next to its free base counts once.
     *
     * @var array<string, string>
     */
    public const FAMILIES = [
        'wordpress-seo' => 'wordpress-seo',
        'wordpress-seo-premium' => 'wordpress-seo',
        'seo-by-rank-math' => 'seo-by-rank-math',
        'seo-by-rank-math-pro' => 'seo-by-rank-math',
        'all-in-one-seo-pack' => 'all-in-one-seo-pack',
        'all-in-one-seo-pack-pro' => 'all-in-one-seo-pack',
        'wp-seopress' => 'wp-seopress',
        'wp-seopress-pro' => 'wp-seopress',
        'autodescription' => 'autodescription',
        'slim-seo' => 'slim-seo',
        'squirrly-seo' => 'squirrly-seo',
    ];

    /**
     * @param array<int, PluginReader>|null $readers stand-ins in tests; null builds the real ones
     * @return array<string, mixed>
     */
    public static function run(?array $readers = null, ?ReadBudget $budget = null): array
    {
        $budget = $budget ?? new ReadBudget(self::BUDGET_SECONDS);
        $readers = $readers ?? self::readers($budget);
        $activeFamilies = self::activeSeoPlugins();
        $section = ['active_seo_plugins' => ['count' => count($activeFamilies), 'slugs' => $activeFamilies]];

        $reader = null;

        foreach ($readers as $candidate) {
            if ($candidate->isActive()) {
                $reader = $candidate;
                break;
            }
        }

        if ($reader === null) {
            return $section + ['unsupported' => 'no_seo_plugin'];
        }

        $version = $reader->version();
        $supported = $version !== null && $reader->supports($version);
        $section['plugin'] = ['slug' => $reader->slug(), 'version' => $version ?? '0', 'supported' => $supported];

        if (! $supported) {
            return $section + ['unsupported' => 'version'];
        }

        $postTypes = self::postTypes();
        $published = self::publishedByType($postTypes, $budget);
        $read = $reader->read($postTypes);
        $types = [];

        foreach ($postTypes as $type) {
            $types[] = [
                'type' => $type,
                'published' => $published[$type] ?? null,
                'noindex' => $read['noindex'][$type] ?? null,
            ];
        }

        return $section + [
            'post_types' => $types,
            'type_noindex' => array_values(array_intersect($read['type_noindex'], $postTypes)),
            'orphans' => $read['orphans'],
            'sitemap_enabled' => $read['sitemap_enabled'],
            'organization_set' => $read['organization_set'],
            'indexing_complete' => $read['indexing_complete'],
            'duration_ms' => $budget->elapsedMs(),
            'budget_exhausted' => $budget->exhausted(),
        ];
    }

    /**
     * @return array<int, PluginReader>
     */
    public static function readers(ReadBudget $budget): array
    {
        return array_map(static function (string $class) use ($budget): PluginReader {
            return new $class($budget);
        }, self::READERS);
    }

    /**
     * The public post types that hold content (attachments are media, not
     * content), bounded in number and name length.
     *
     * @return array<int, string>
     */
    public static function postTypes(): array
    {
        $names = function_exists('get_post_types') ? get_post_types(['public' => true], 'names') : [];
        $types = [];

        foreach ((array) $names as $name) {
            if (! is_string($name) || $name === 'attachment' || strlen($name) > self::MAX_POST_TYPE_LENGTH || preg_match('/^[a-z0-9_-]+$/', $name) !== 1) {
                continue;
            }

            $types[] = $name;

            if (count($types) >= self::MAX_POST_TYPES) {
                break;
            }
        }

        return $types;
    }

    /**
     * Distinct active SEO plugin products, by base slug.
     *
     * @return array<int, string>
     */
    public static function activeSeoPlugins(): array
    {
        if (! function_exists('get_plugins') || ! function_exists('is_plugin_active')) {
            return [];
        }

        $families = [];

        foreach (array_keys((array) get_plugins()) as $file) {
            $slug = strtolower(strpos($file, '/') !== false ? (string) strstr($file, '/', true) : (string) preg_replace('/\.php$/', '', $file));

            if (isset(self::FAMILIES[$slug]) && is_plugin_active($file)) {
                $families[self::FAMILIES[$slug]] = true;
            }
        }

        return array_keys($families);
    }

    /**
     * @param array<int, string> $postTypes
     * @return array<string, int|null>
     */
    private static function publishedByType(array $postTypes, ReadBudget $budget): array
    {
        $counts = [];

        foreach ($postTypes as $type) {
            $counts[$type] = null;
        }

        if ($postTypes === [] || ! $budget->open()) {
            return $counts;
        }

        $wpdb = $GLOBALS['wpdb'];
        $sql = $wpdb->prepare(
            "SELECT post_type AS type, COUNT(*) AS n FROM {$wpdb->prefix}posts WHERE post_status = 'publish' AND post_type IN (".implode(', ', array_fill(0, count($postTypes), '%s')).') GROUP BY post_type',
            ...$postTypes
        );
        $rows = is_string($sql) ? $wpdb->get_results($sql, 'ARRAY_A') : null;

        if (! is_array($rows)) {
            return $counts;
        }

        foreach ($postTypes as $type) {
            $counts[$type] = 0;
        }

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['type'], $row['n']) && array_key_exists((string) $row['type'], $counts)) {
                $counts[(string) $row['type']] = max(0, (int) $row['n']);
            }
        }

        return $counts;
    }
}
