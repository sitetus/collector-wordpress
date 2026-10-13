<?php

namespace Sitetus\WP\Readers;

/**
 * All in One SEO (all-in-one-seo-pack). Storage verified on 5.0.2.1 (02.10.2026):
 *  - {prefix}aioseo_posts: one row per post edited under the plugin;
 *    robots_default (1 = inherit the type's setting), robots_noindex.
 *  - option aioseo_options (JSON): sitemap.general.enable,
 *    searchAppearance.global.schema.{siteRepresents, organizationName,
 *    organizationLogo}.
 *  - option aioseo_options_dynamic (JSON): searchAppearance.postTypes.{type}
 *    .show (false = not shown in search) and .advanced.robotsMeta.{default,
 *    noindex}.
 * No link counter in the free plugin, so orphans are never reported.
 */
final class AioseoReader extends AbstractReader
{
    public function slug(): string
    {
        return 'all-in-one-seo-pack';
    }

    protected function pluginFile(): string
    {
        return 'all-in-one-seo-pack/all_in_one_seo_pack.php';
    }

    protected function versionConstant(): string
    {
        return 'AIOSEO_VERSION';
    }

    protected function minVersion(): string
    {
        return '4.1';
    }

    protected function maxVersionExclusive(): string
    {
        return '6.0';
    }

    public function read(array $postTypes): array
    {
        $options = $this->optionJson('aioseo_options');
        $dynamic = $this->optionJson('aioseo_options_dynamic');
        $posts = $this->postsTable();
        $table = $this->table('aioseo_posts');

        $noindex = null;

        if ($postTypes !== [] && $this->tableExists($table)) {
            $noindex = $this->countsByType(
                "SELECT p.post_type AS type, COUNT(*) AS n FROM {$posts} p INNER JOIN {$table} a ON a.post_id = p.ID WHERE p.post_status = 'publish' AND a.robots_default = 0 AND a.robots_noindex = 1 AND p.post_type IN (".self::placeholders($postTypes).') GROUP BY p.post_type',
                $postTypes
            );
        }

        $typeNoindex = [];

        foreach ($postTypes as $type) {
            $settings = self::path($dynamic, ['searchAppearance', 'postTypes', $type]);

            if (! is_array($settings)) {
                continue;
            }

            $robots = self::path($settings, ['advanced', 'robotsMeta']);
            $customNoindex = is_array($robots) && ($robots['default'] ?? true) === false && ($robots['noindex'] ?? false) === true;

            if (($settings['show'] ?? true) === false || $customNoindex) {
                $typeNoindex[] = $type;
            }
        }

        $sitemap = self::path($options, ['sitemap', 'general', 'enable']);
        $schema = self::path($options, ['searchAppearance', 'global', 'schema']);
        $organization = null;

        if (is_array($schema) && ($schema['siteRepresents'] ?? '') === 'organization') {
            $organization = trim((string) ($schema['organizationName'] ?? '')) !== ''
                && trim((string) ($schema['organizationLogo'] ?? '')) !== '';
        } elseif (is_array($schema) && ($schema['siteRepresents'] ?? '') === 'person') {
            $organization = false;
        }

        return [
            'noindex' => self::perType($postTypes, $noindex),
            'type_noindex' => $typeNoindex,
            'orphans' => null,
            'sitemap_enabled' => is_bool($sitemap) ? $sitemap : null,
            'organization_set' => $organization,
            'indexing_complete' => null,
        ];
    }
}
