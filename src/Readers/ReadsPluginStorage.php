<?php

namespace Sitetus\WP\Readers;

/**
 * The reader helpers that are not specific to SEO plugins: plugin
 * activity and version from WordPress' plugin list (or the plugin's own
 * version constant), the support window, option reading, and prepared
 * read-only queries through $wpdb under the module's time budget. The
 * mail and cache readers (0.4.0) use this trait; AbstractReader keeps
 * the same helpers for the SEO readers alongside its per-post-type ones
 * (adopting the trait there is a one-line change for a later release).
 * Nothing here writes, and no query result other than a count or an
 * option flag ever leaves a reader.
 */
trait ReadsPluginStorage
{
    /** @var ReadBudget */
    protected $budget;

    public function __construct(ReadBudget $budget)
    {
        $this->budget = $budget;
    }

    /**
     * The main plugin file relative to wp-content/plugins (slug/file.php).
     */
    abstract protected function pluginFile(): string;

    /**
     * The constant the plugin defines with its version, if any.
     */
    abstract protected function versionConstant(): string;

    /**
     * The lowest version whose storage this reader was written against.
     */
    abstract protected function minVersion(): string;

    /**
     * The first version this reader does NOT claim to know.
     */
    abstract protected function maxVersionExclusive(): string;

    public function isActive(): bool
    {
        return function_exists('is_plugin_active') && is_plugin_active($this->pluginFile());
    }

    public function version(): ?string
    {
        $constant = $this->versionConstant();

        if ($constant !== '' && defined($constant) && is_scalar(constant($constant))) {
            return $this->cleanVersion((string) constant($constant));
        }

        $plugins = function_exists('get_plugins') ? get_plugins() : [];
        $header = is_array($plugins) ? ($plugins[$this->pluginFile()]['Version'] ?? null) : null;

        return is_scalar($header) ? $this->cleanVersion((string) $header) : null;
    }

    public function supports(string $version): bool
    {
        return version_compare($version, $this->minVersion(), '>=')
            && version_compare($version, $this->maxVersionExclusive(), '<');
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    protected function option(string $name, $default = null)
    {
        return get_option($name, $default);
    }

    /**
     * @return array<string, mixed>
     */
    protected function optionArray(string $name): array
    {
        $value = $this->option($name, []);

        return is_array($value) ? $value : [];
    }

    /**
     * An option stored as a JSON string.
     *
     * @return array<string, mixed>
     */
    protected function optionJson(string $name): array
    {
        $value = $this->option($name, '');
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Walks a nested array by path; null when any step is missing.
     *
     * @param array<string, mixed> $tree
     * @param array<int, string> $path
     * @return mixed
     */
    protected static function path(array $tree, array $path)
    {
        $node = $tree;

        foreach ($path as $step) {
            if (! is_array($node) || ! array_key_exists($step, $node)) {
                return null;
            }

            $node = $node[$step];
        }

        return $node;
    }

    protected function table(string $suffix): string
    {
        return $this->wpdb()->prefix.$suffix;
    }

    protected function tableExists(string $table): bool
    {
        $found = $this->var('SHOW TABLES LIKE %s', [$table]);

        return is_string($found) && $found === $table;
    }

    /**
     * A single scalar from a prepared, read-only query; null when the budget
     * is spent, the query fails or nothing comes back.
     *
     * @param array<int, string|int|float> $params
     * @return string|null
     */
    protected function var(string $sql, array $params = []): ?string
    {
        if (! $this->budget->open()) {
            return null;
        }

        $wpdb = $this->wpdb();
        $prepared = $params === [] ? $sql : $wpdb->prepare($sql, ...$params);

        if (! is_string($prepared)) {
            return null;
        }

        $value = $wpdb->get_var($prepared);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * A bounded count; null when it could not be measured.
     *
     * @param array<int, string|int|float> $params
     */
    protected function count(string $sql, array $params = []): ?int
    {
        $value = $this->var($sql, $params);

        return $value === null || ! is_numeric($value) ? null : max(0, (int) $value);
    }

    /**
     * @return object the global $wpdb (a stand-in in tests)
     */
    protected function wpdb(): object
    {
        return $GLOBALS['wpdb'];
    }

    private function cleanVersion(string $version): string
    {
        return substr(trim($version), 0, 32);
    }
}
