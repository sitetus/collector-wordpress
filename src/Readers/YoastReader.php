<?php

namespace Sitetus\WP\Readers;

/**
 * Yoast SEO (wordpress-seo). Storage verified on 28.6 (02.10.2026):
 *  - {prefix}yoast_indexable: one row per object; object_type 'post',
 *    object_sub_type = post type, post_status, is_robots_noindex (1 /
 *    NULL), incoming_link_count (NULL = never linked, filled only after
 *    the link indexing ran).
 *  - option wpseo: enable_xml_sitemap, enable_text_link_counter,
 *    indexables_indexing_completed.
 *  - option wpseo_titles: noindex-{post_type} (bool), company_or_person,
 *    company_name, company_logo, company_logo_id.
 * The indexable table exists since 14.0.
 */
final class YoastReader extends AbstractReader
{
    public function slug(): string
    {
        return 'wordpress-seo';
    }

    protected function pluginFile(): string
    {
        return 'wordpress-seo/wp-seo.php';
    }

    protected function versionConstant(): string
    {
        return 'WPSEO_VERSION';
    }

    protected function minVersion(): string
    {
        return '14.0';
    }

    protected function maxVersionExclusive(): string
    {
        return '30.0';
    }

    public function read(array $postTypes): array
    {
        $general = $this->optionArray('wpseo');
        $titles = $this->optionArray('wpseo_titles');
        $indexable = $this->table('yoast_indexable');
        $hasTable = $this->tableExists($indexable);
        $indexingComplete = array_key_exists('indexables_indexing_completed', $general) ? (bool) $general['indexables_indexing_completed'] : null;

        $noindex = null;

        if ($hasTable && $postTypes !== []) {
            $noindex = $this->countsByType(
                "SELECT object_sub_type AS type, COUNT(*) AS n FROM {$indexable} WHERE object_type = 'post' AND post_status = 'publish' AND is_robots_noindex = 1 AND object_sub_type IN (".self::placeholders($postTypes).') GROUP BY object_sub_type',
                $postTypes
            );
        }

        $typeNoindex = [];

        foreach ($postTypes as $type) {
            if (! empty($titles['noindex-'.$type])) {
                $typeNoindex[] = $type;
            }
        }

        $orphans = null;

        // Incoming counts are only meaningful once the link counter is on and the
        // indexing has run; before that NULL means "not counted yet", not "orphan".
        if ($hasTable && $postTypes !== [] && ! empty($general['enable_text_link_counter']) && $indexingComplete === true) {
            $orphans = $this->count(
                "SELECT COUNT(*) FROM {$indexable} WHERE object_type = 'post' AND post_status = 'publish' AND (incoming_link_count IS NULL OR incoming_link_count = 0) AND object_sub_type IN (".self::placeholders($postTypes).')',
                $postTypes
            );
        }

        $organization = null;

        if (($titles['company_or_person'] ?? '') === 'company') {
            $organization = trim((string) ($titles['company_name'] ?? '')) !== ''
                && (trim((string) ($titles['company_logo'] ?? '')) !== '' || (int) ($titles['company_logo_id'] ?? 0) > 0);
        } elseif (($titles['company_or_person'] ?? '') === 'person') {
            $organization = false;
        }

        return [
            'noindex' => self::perType($postTypes, $noindex),
            'type_noindex' => $typeNoindex,
            'orphans' => $orphans,
            'sitemap_enabled' => array_key_exists('enable_xml_sitemap', $general) ? (bool) $general['enable_xml_sitemap'] : null,
            'organization_set' => $organization,
            'indexing_complete' => $indexingComplete,
        ];
    }
}
