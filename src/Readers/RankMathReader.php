<?php

namespace Sitetus\WP\Readers;

/**
 * Rank Math SEO (seo-by-rank-math). Storage verified on 1.0.279 (02.10.2026):
 *  - post meta rank_math_robots: serialized array of robots tokens
 *    ("noindex" among them) — only on posts saved under Rank Math.
 *  - option rank-math-options-titles: pt_{type}_custom_robots ('on'|'off')
 *    and pt_{type}_robots (array); robots_global (array) is the default
 *    when custom robots are off; knowledgegraph_type ('company'|'person'),
 *    knowledgegraph_name, knowledgegraph_logo.
 *  - option rank_math_modules: active module ids ('sitemap', 'link-counter').
 *  - {prefix}rank_math_internal_meta: object_id, internal_link_count,
 *    external_link_count, incoming_link_count — rows only for posts saved
 *    while the Link Counter module was on.
 */
final class RankMathReader extends AbstractReader
{
    public function slug(): string
    {
        return 'seo-by-rank-math';
    }

    protected function pluginFile(): string
    {
        return 'seo-by-rank-math/rank-math.php';
    }

    protected function versionConstant(): string
    {
        return 'RANK_MATH_VERSION';
    }

    protected function minVersion(): string
    {
        return '1.0.0';
    }

    protected function maxVersionExclusive(): string
    {
        return '1.1';
    }

    public function read(array $postTypes): array
    {
        $titles = $this->optionArray('rank-math-options-titles');
        $modules = $this->option('rank_math_modules', []);
        $modules = is_array($modules) ? array_values(array_filter($modules, 'is_string')) : [];
        $posts = $this->postsTable();
        $meta = $this->postmetaTable();

        $noindex = null;

        if ($postTypes !== []) {
            $noindex = $this->countsByType(
                "SELECT p.post_type AS type, COUNT(*) AS n FROM {$posts} p INNER JOIN {$meta} m ON m.post_id = p.ID AND m.meta_key = 'rank_math_robots' WHERE p.post_status = 'publish' AND m.meta_value LIKE %s AND p.post_type IN (".self::placeholders($postTypes).') GROUP BY p.post_type',
                array_merge(['%"noindex"%'], $postTypes)
            );
        }

        $globalRobots = is_array($titles['robots_global'] ?? null) ? $titles['robots_global'] : [];
        $typeNoindex = [];

        foreach ($postTypes as $type) {
            $custom = ($titles['pt_'.$type.'_custom_robots'] ?? 'off') === 'on';
            $robots = $custom
                ? (is_array($titles['pt_'.$type.'_robots'] ?? null) ? $titles['pt_'.$type.'_robots'] : [])
                : $globalRobots;

            if (in_array('noindex', $robots, true)) {
                $typeNoindex[] = $type;
            }
        }

        $orphans = null;
        $internalMeta = $this->table('rank_math_internal_meta');

        if ($postTypes !== [] && in_array('link-counter', $modules, true) && $this->tableExists($internalMeta)) {
            $counted = $this->count("SELECT COUNT(*) FROM {$internalMeta}");

            // No rows at all means the counter never ran over this content; an
            // honest "not kept" beats reporting every post as an orphan.
            if ($counted !== null && $counted > 0) {
                $orphans = $this->count(
                    "SELECT COUNT(*) FROM {$posts} p INNER JOIN {$internalMeta} m ON m.object_id = p.ID WHERE p.post_status = 'publish' AND m.incoming_link_count = 0 AND p.post_type IN (".self::placeholders($postTypes).')',
                    $postTypes
                );
            }
        }

        $organization = null;

        if (($titles['knowledgegraph_type'] ?? '') === 'company') {
            $organization = trim((string) ($titles['knowledgegraph_name'] ?? '')) !== ''
                && trim((string) ($titles['knowledgegraph_logo'] ?? '')) !== '';
        } elseif (($titles['knowledgegraph_type'] ?? '') === 'person') {
            $organization = false;
        }

        return [
            'noindex' => self::perType($postTypes, $noindex),
            'type_noindex' => $typeNoindex,
            'orphans' => $orphans,
            'sitemap_enabled' => in_array('sitemap', $modules, true),
            'organization_set' => $organization,
            'indexing_complete' => null,
        ];
    }
}
