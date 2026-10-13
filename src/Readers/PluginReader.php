<?php

namespace Sitetus\WP\Readers;

/**
 * One third-party SEO plugin as the seo_plugin module sees it. A reader
 * answers four questions and nothing else: which plugin, is it active,
 * do I know this version's storage, and what do its records say — as
 * counts and booleans only. Adding the next plugin means adding one
 * class that implements this and listing it in SeoPluginModule::READERS.
 */
interface PluginReader
{
    /**
     * The wordpress.org directory slug (also the inventory name).
     */
    public function slug(): string;

    public function isActive(): bool;

    /**
     * The installed version, or null when it cannot be told.
     */
    public function version(): ?string;

    /**
     * Whether this reader knows the storage layout of the given version.
     * An unknown version yields `unsupported: version` rather than a guess.
     */
    public function supports(string $version): bool;

    /**
     * Reads the plugin's records for the given public post types. Every
     * value is a count, a boolean, a post type name or null ("not kept
     * by this plugin" / "not measurable within the budget").
     *
     * @param array<int, string> $postTypes public post type names (bounded by the module)
     * @return array{noindex: array<string, int|null>, type_noindex: array<int, string>, orphans: int|null, sitemap_enabled: bool|null, organization_set: bool|null, indexing_complete: bool|null}
     */
    public function read(array $postTypes): array;
}
