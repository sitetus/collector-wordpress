<?php

namespace Sitetus\WP;

/**
 * The opt-in `core_integrity` module (0.5.0): compares the WordPress core
 * files on disk with the official MD5 list WordPress.org publishes for the
 * installed version and language package.
 *
 * The list comes from WordPress' own get_core_checksums(): the SITE asks
 * api.wordpress.org — the service it already contacts for update checks —
 * once per signed request that includes this module. The request carries
 * the WordPress version and the locale of the installed package (plus the
 * User-Agent WordPress adds to every request it makes). Nothing is cached
 * and nothing is written; a file is never repaired, replaced or deleted.
 *
 * What leaves the site: counts, and at most LIST_MAX relative file names
 * per list. Never a hash, a file's contents or a modification time.
 *
 *  - modified:   official files whose MD5 differs;
 *  - missing:    official files absent on disk;
 *  - unexpected: PHP files under wp-admin/ and wp-includes/ that the
 *                official list does not know;
 *  - root_extra: PHP files in the site root that the official list does
 *                not know (information only; wp-config.php is left out).
 *
 * wp-content/ is skipped entirely (bundled themes and plugins are
 * optional), and the root-level sample configuration, readme and licence
 * files count as neither modified nor missing (sites delete or localise
 * them). Without an official list — no network, an unknown, beta or
 * nightly version — the module answers `unsupported`, never a verdict.
 */
final class CoreIntegrityModule
{
    public const ID = 'core_integrity';

    public const UNSUPPORTED_CHECKSUMS = 'checksums_unavailable';

    /** Official files compared in one run. */
    public const MAX_FILES = 5000;

    /** Bytes read from disk for hashing in one run (a 7.x core without wp-content is about 100 MB). */
    public const MAX_BYTES = 256 * 1024 * 1024;

    /** Wall-clock budget of the comparison and the directory walk (the official list request is WordPress' own, 3 s). */
    public const BUDGET_SECONDS = 5.0;

    /** Directory entries visited while looking for unexpected files. */
    public const MAX_WALK_ENTRIES = 20000;

    /** Files the daily starting point moves forward in the sorted official list (see startOffset()). */
    public const ROTATION_STRIDE = 150;

    public const LIST_MAX = 20;

    public const PATH_MAX_BYTES = 200;

    public const FALLBACK_LOCALE = 'en_US';

    /**
     * @var array<int, string>
     */
    public const CORE_DIRECTORIES = ['wp-admin', 'wp-includes'];

    /**
     * @var array<int, string>
     */
    public const PHP_EXTENSIONS = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar'];

    /**
     * @param array{max_files?: int, max_bytes?: int, seconds?: float, max_walk_entries?: int}|null $budget stand-in limits in tests; null uses the constants
     * @param int|null $now Unix time of the request (picks the day's starting file); null reads the clock
     * @return array<string, mixed>
     */
    public static function run(?array $budget = null, ?int $now = null): array
    {
        $startedAt = microtime(true);
        $limits = [
            'max_files' => (int) ($budget['max_files'] ?? self::MAX_FILES),
            'max_bytes' => (int) ($budget['max_bytes'] ?? self::MAX_BYTES),
            'seconds' => (float) ($budget['seconds'] ?? self::BUDGET_SECONDS),
            'max_walk_entries' => (int) ($budget['max_walk_entries'] ?? self::MAX_WALK_ENTRIES),
        ];

        list($version, $locale) = self::installedCore();
        $unsupported = ['unsupported' => self::UNSUPPORTED_CHECKSUMS, 'version' => $version, 'locale' => $locale];

        if ($version === null || $locale === null) {
            return $unsupported;
        }

        $localeFallback = false;
        $official = self::officialList($version, $locale);

        if ($official === null && $locale !== self::FALLBACK_LOCALE) {
            $official = self::officialList($version, self::FALLBACK_LOCALE);
            $localeFallback = $official !== null;
        }

        $root = realpath(rtrim(ABSPATH, '/\\'));

        if ($official === null || $root === false) {
            return $unsupported;
        }

        $modified = ['count' => 0, 'paths' => []];
        $missing = ['count' => 0, 'paths' => []];
        $unexpected = ['count' => 0, 'paths' => []];
        $rootExtra = ['count' => 0, 'names' => []];
        $checked = 0;
        $unreadable = 0;
        $bytes = 0;
        $partial = false;
        // The clock starts after the list arrived: the budget bounds the work on disk.
        $deadline = microtime(true) + $limits['seconds'];

        $visited = 0;

        // The walk comes first: it only lists directories, so a slow disk cannot starve it.
        foreach (self::CORE_DIRECTORIES as $directory) {
            $pending = [$directory];

            while ($pending !== []) {
                $current = array_pop($pending);
                $entries = is_link($root.'/'.$current) ? false : @scandir($root.'/'.$current);

                if ($entries === false) {
                    // A core directory that does not exist is already told by its missing files.
                    $unreadable += file_exists($root.'/'.$current) || is_link($root.'/'.$current) ? 1 : 0;

                    continue;
                }

                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    if ($visited >= $limits['max_walk_entries'] || microtime(true) > $deadline) {
                        $partial = true;
                        break 3;
                    }

                    $visited++;
                    $relative = $current.'/'.$entry;

                    if (is_link($root.'/'.$relative)) {
                        // Never followed; a link inside a core directory is not part of any release.
                        $unreadable++;

                        continue;
                    }

                    if (is_dir($root.'/'.$relative)) {
                        $pending[] = $relative;

                        continue;
                    }

                    if (self::isPhp($entry) && ! isset($official[$relative])) {
                        self::note($unexpected, 'paths', $relative);
                    }
                }
            }
        }

        foreach ((array) @scandir($root) as $entry) {
            if (! is_string($entry) || $entry === 'wp-config.php' || ! self::isPhp($entry) || isset($official[$entry]) || is_dir($root.'/'.$entry)) {
                continue;
            }

            self::note($rootExtra, 'names', $entry);
        }

        $comparable = self::comparable($official, $localeFallback);
        $total = count($comparable);
        $offset = self::startOffset($total, $now ?? time());

        for ($step = 0; $step < $total; $step++) {
            $relative = $comparable[($offset + $step) % $total];
            $hash = $official[$relative];

            if ($checked >= $limits['max_files'] || microtime(true) > $deadline) {
                $partial = true;
                break;
            }

            $path = $root.'/'.$relative;
            $real = realpath($path);

            if ($real === false) {
                if (is_link($path)) {
                    $unreadable++;
                } else {
                    self::note($missing, 'paths', $relative);
                }

                continue;
            }

            if (! self::isInside($real, $root)) {
                $unreadable++;

                continue;
            }

            if (! is_file($real)) {
                self::note($missing, 'paths', $relative);

                continue;
            }

            $size = is_readable($real) ? @filesize($real) : false;

            if ($size === false) {
                $unreadable++;

                continue;
            }

            if ($bytes + $size > $limits['max_bytes']) {
                $partial = true;
                break;
            }

            $actual = @md5_file($real);

            if (! is_string($actual)) {
                $unreadable++;

                continue;
            }

            $bytes += $size;
            $checked++;

            if (! hash_equals($hash, $actual)) {
                self::note($modified, 'paths', $relative);
            }
        }

        return [
            'version' => $version,
            'locale' => $localeFallback ? self::FALLBACK_LOCALE : $locale,
            'locale_fallback' => $localeFallback,
            'checked' => $checked,
            'partial' => $partial,
            'unreadable' => $unreadable,
            'modified' => $modified,
            'missing' => $missing,
            'unexpected' => $unexpected,
            'root_extra' => $rootExtra,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * The official files that are compared, in a fixed (sorted) order:
     * the ignored root files are left out, and so is version.php when the
     * en_US list stands in for a language package.
     *
     * @param array<string, string> $official
     * @return array<int, string>
     */
    public static function comparable(array $official, bool $localeFallback): array
    {
        $comparable = [];

        foreach ($official as $relative => $hash) {
            if (! self::isIgnored($relative) && ! ($localeFallback && $relative === 'wp-includes/version.php')) {
                $comparable[] = $relative;
            }
        }

        sort($comparable, SORT_STRING);

        return $comparable;
    }

    /**
     * Where today's comparison starts in the sorted list. On a cold disk
     * only part of the list fits the time budget; a fixed start would
     * leave the tail uncompared for ever. The module keeps no state, so
     * the start is derived from the calendar instead:
     *
     *     offset = (UTC day number × ROTATION_STRIDE) mod N
     *
     * and the list is walked circularly from there (N files compared at
     * most, so a run that finishes sees every file whatever the offset).
     * Each day the start moves ROTATION_STRIDE files forward. As long as
     * one request compares at least ROTATION_STRIDE files — the slowest
     * rate observed was 172 files in the 5-second budget on a cold disk
     * behind a real-time virus scanner — the stretch compared on one day
     * reaches the next day's start, so the days leave no gap, and after
     * ceil(N / ROTATION_STRIDE) consecutive days the starts have gone once
     * round the list: every file has been compared. For a 7.x core
     * (N ≈ 3,335) that is 23 days at the worst rate; a warm disk compares
     * the whole list in one request.
     */
    public static function startOffset(int $total, int $now): int
    {
        return $total > 0 ? (int) ((intdiv(max(0, $now), 86400) * self::ROTATION_STRIDE) % $total) : 0;
    }

    /**
     * The installed release and its language package, read the way
     * WordPress' own tools do: from wp-includes/version.php ($wp_version,
     * $wp_local_package), never from the site-language setting — a site
     * may run in another language than the package it was installed from.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function installedCore(): array
    {
        $wp_version = null;
        $wp_local_package = null;
        $file = rtrim(ABSPATH, '/\\').'/'.(defined('WPINC') ? WPINC : 'wp-includes').'/version.php';

        if (is_file($file) && is_readable($file)) {
            include $file;
        }

        if (! is_string($wp_version) || $wp_version === '') {
            $wp_version = function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '';
        }

        $locale = is_string($wp_local_package) && $wp_local_package !== '' ? $wp_local_package : self::FALLBACK_LOCALE;

        return [
            preg_match('/^[0-9][0-9A-Za-z.+-]{0,31}$/', $wp_version) === 1 ? $wp_version : null,
            preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]{2,8}){0,2}$/', $locale) === 1 ? $locale : null,
        ];
    }

    /**
     * The official list for one release and locale, wp-content/ left out.
     *
     * @return array<string, string>|null relative path → MD5; null when WordPress.org has no list
     */
    public static function officialList(string $version, string $locale): ?array
    {
        if (! function_exists('get_core_checksums')) {
            $include = rtrim(ABSPATH, '/\\').'/wp-admin/includes/update.php';

            if (is_file($include)) {
                require_once $include;
            }
        }

        if (! function_exists('get_core_checksums')) {
            return null;
        }

        $checksums = get_core_checksums($version, $locale);

        if (! is_array($checksums)) {
            return null;
        }

        // An older answer shape nests the list under the version.
        if (isset($checksums[$version]) && is_array($checksums[$version])) {
            $checksums = $checksums[$version];
        }

        $official = [];

        foreach ($checksums as $relative => $hash) {
            if (! is_string($relative) || ! is_string($hash) || preg_match('/^[a-f0-9]{32}$/', $hash) !== 1) {
                continue;
            }

            $relative = str_replace('\\', '/', $relative);

            if ($relative === '' || $relative[0] === '/' || strpos($relative, ':') !== false || in_array('..', explode('/', $relative), true) || strpos($relative, 'wp-content/') === 0) {
                continue;
            }

            $official[$relative] = $hash;
        }

        return $official === [] ? null : $official;
    }

    /**
     * Root-level files sites delete or localise: the sample configuration
     * and the readme / licence files of any language package.
     */
    public static function isIgnored(string $relative): bool
    {
        if (strpos($relative, '/') !== false) {
            return false;
        }

        $extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));

        return $relative === 'wp-config-sample.php' || $extension === 'html' || $extension === 'txt';
    }

    private static function isPhp(string $name): bool
    {
        return in_array(strtolower((string) pathinfo($name, PATHINFO_EXTENSION)), self::PHP_EXTENSIONS, true);
    }

    private static function isInside(string $real, string $root): bool
    {
        return strpos($real, rtrim($root, '/\\').DIRECTORY_SEPARATOR) === 0;
    }

    /**
     * Counts every hit; lists the first LIST_MAX names that are bounded,
     * printable and valid UTF-8 (anything else is counted, not named).
     *
     * @param array<string, mixed> $bucket
     */
    private static function note(array &$bucket, string $list, string $relative): void
    {
        $bucket['count']++;

        if (count($bucket[$list]) < self::LIST_MAX
            && strlen($relative) <= self::PATH_MAX_BYTES
            && preg_match('/[\x00-\x1F\x7F]/', $relative) !== 1
            && preg_match('//u', $relative) === 1) {
            $bucket[$list][] = $relative;
        }
    }
}
