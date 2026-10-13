<?php
/**
 * Reader tests without WordPress: a fake $wpdb that answers prepared
 * read-only queries from a script, stubbed options and plugin list, and
 * the three readers plus the module orchestration asserted against the
 * storage shapes verified on real installs (Yoast 28.6, Rank Math
 * 1.0.279, All in One SEO 5.0.2.1 — 02.10.2026). Run:
 *
 *   php -d zend.assertions=1 tests/readers.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

$GLOBALS['options'] = [];
$GLOBALS['plugins'] = [];
$GLOBALS['active'] = [];

function get_option(string $name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function get_plugins(): array { return $GLOBALS['plugins']; }
function is_plugin_active(string $file): bool { return in_array($file, $GLOBALS['active'], true); }
function get_post_types(array $args = [], string $output = 'names'): array { return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment', 'product' => 'product']; }

/**
 * Prepared-query stand-in: %s/%d are filled like $wpdb->prepare does, and
 * every query the readers may send is answered from a list of regex
 * patterns. Every prepared statement is recorded so a test can assert that
 * nothing but SELECT/SHOW left the reader.
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';

    /** @var array<int, array{0: string, 1: mixed}> */
    public array $answers = [];

    /** @var array<int, string> */
    public array $log = [];

    public function prepare(string $query, ...$args): string
    {
        $query = str_replace("'%s'", '%s', $query);
        $i = 0;

        return (string) preg_replace_callback('/%[sdf]/', function (array $match) use (&$i, $args): string {
            $value = $args[$i++] ?? '';

            return $match[0] === '%s' ? "'".str_replace("'", "\\'", (string) $value)."'" : (string) $value;
        }, $query);
    }

    public function get_var(string $query)
    {
        return $this->answer($query);
    }

    public function get_results(string $query, string $output = 'OBJECT')
    {
        return $this->answer($query);
    }

    private function answer(string $query)
    {
        $this->log[] = $query;
        assert(preg_match('/^\s*(SELECT|SHOW)\b/i', $query) === 1, 'read-only query: '.$query);

        foreach ($this->answers as [$pattern, $value]) {
            if (preg_match($pattern, $query) === 1) {
                return $value;
            }
        }

        return null;
    }
}

require dirname(__DIR__).'/includes/autoload.php';

use Sitetus\WP\Readers\AioseoReader;
use Sitetus\WP\Readers\RankMathReader;
use Sitetus\WP\Readers\ReadBudget;
use Sitetus\WP\Readers\YoastReader;
use Sitetus\WP\SeoPluginModule;

function reset_world(): FakeWpdb
{
    $GLOBALS['options'] = [];
    $GLOBALS['plugins'] = [];
    $GLOBALS['active'] = [];
    $GLOBALS['wpdb'] = new FakeWpdb();

    return $GLOBALS['wpdb'];
}

function install(string $file, string $version, bool $active = true): void
{
    $GLOBALS['plugins'][$file] = ['Name' => $file, 'Version' => $version];

    if ($active) {
        $GLOBALS['active'][] = $file;
    }
}

$types = ['post', 'page', 'product'];

// --- Yoast ---------------------------------------------------------------
$db = reset_world();
install('wordpress-seo/wp-seo.php', '28.6');
$GLOBALS['options']['wpseo'] = ['enable_xml_sitemap' => true, 'enable_text_link_counter' => true, 'indexables_indexing_completed' => true];
$GLOBALS['options']['wpseo_titles'] = ['noindex-post' => true, 'noindex-page' => false, 'company_or_person' => 'company', 'company_name' => 'Deneme', 'company_logo' => '', 'company_logo_id' => 0];
$db->answers = [
    ['/SHOW TABLES LIKE \'wp_yoast_indexable\'/', 'wp_yoast_indexable'],
    ['/is_robots_noindex = 1 .*GROUP BY object_sub_type/', [['type' => 'post', 'n' => '2']]],
    ['/incoming_link_count IS NULL OR incoming_link_count = 0/', '1'],
];
$yoast = new YoastReader(new ReadBudget(2.0));
assert($yoast->isActive() && $yoast->version() === '28.6' && $yoast->supports('28.6') && ! $yoast->supports('13.5') && ! $yoast->supports('30.0'), 'yoast activity, version, support window');
$read = $yoast->read($types);
assert($read['noindex'] === ['post' => 2, 'page' => 0, 'product' => 0], 'yoast noindex per type: '.json_encode($read['noindex']));
assert($read['type_noindex'] === ['post'], 'yoast type-level noindex from wpseo_titles');
assert($read['orphans'] === 1, 'yoast orphans from incoming_link_count');
assert($read['sitemap_enabled'] === true && $read['indexing_complete'] === true, 'yoast sitemap and indexing flags');
assert($read['organization_set'] === false, 'yoast company without logo is not set');
assert(strpos($db->log[1], "IN ('post', 'page', 'product')") !== false, 'post types are bound as prepared values: '.$db->log[1]);

// Without a finished index the orphan count is not claimed, and a person site has no organization.
$GLOBALS['options']['wpseo']['indexables_indexing_completed'] = false;
$GLOBALS['options']['wpseo_titles']['company_or_person'] = 'person';
$read = (new YoastReader(new ReadBudget(2.0)))->read($types);
assert($read['orphans'] === null && $read['indexing_complete'] === false && $read['organization_set'] === false, 'yoast: unfinished index → no orphan claim');

// Missing table (plugin never ran its migrations): counts are null, not zero.
$db->answers = [];
$read = (new YoastReader(new ReadBudget(2.0)))->read($types);
assert($read['noindex'] === ['post' => null, 'page' => null, 'product' => null], 'yoast: no indexable table → null counts');

// --- Rank Math -----------------------------------------------------------
$db = reset_world();
install('seo-by-rank-math/rank-math.php', '1.0.279');
$GLOBALS['options']['rank_math_modules'] = ['link-counter', 'sitemap', 'rich-snippet'];
$GLOBALS['options']['rank-math-options-titles'] = [
    'robots_global' => ['index'],
    'pt_post_custom_robots' => 'on', 'pt_post_robots' => ['noindex'],
    'pt_page_custom_robots' => 'off', 'pt_page_robots' => ['noindex'],
    'knowledgegraph_type' => 'company', 'knowledgegraph_name' => 'Deneme', 'knowledgegraph_logo' => 'https://example.test/logo.png',
];
$db->answers = [
    ['/meta_key = \'rank_math_robots\'.*LIKE \'%"noindex"%\'/', [['type' => 'post', 'n' => '2']]],
    ['/SHOW TABLES LIKE \'wp_rank_math_internal_meta\'/', 'wp_rank_math_internal_meta'],
    ['/SELECT COUNT\(\*\) FROM wp_rank_math_internal_meta$/', '11'],
    ['/m\.incoming_link_count = 0/', '1'],
];
$rankMath = new RankMathReader(new ReadBudget(2.0));
assert($rankMath->supports('1.0.279') && ! $rankMath->supports('1.1.0'), 'rank math support window');
$read = $rankMath->read($types);
assert($read['noindex'] === ['post' => 2, 'page' => 0, 'product' => 0], 'rank math noindex from post meta');
assert($read['type_noindex'] === ['post'], 'rank math: pt_page_robots ignored while custom robots are off');
assert($read['orphans'] === 1 && $read['sitemap_enabled'] === true && $read['organization_set'] === true && $read['indexing_complete'] === null, 'rank math orphans/sitemap/organization');

// Global robots noindex closes every type that inherits; no internal_meta rows → orphans unknown.
$GLOBALS['options']['rank-math-options-titles']['robots_global'] = ['noindex'];
$db->answers[2] = ['/SELECT COUNT\(\*\) FROM wp_rank_math_internal_meta$/', '0'];
$read = (new RankMathReader(new ReadBudget(2.0)))->read($types);
assert($read['type_noindex'] === ['post', 'page', 'product'] && $read['orphans'] === null, 'rank math: inherited global noindex, empty counter table');

// --- All in One SEO ------------------------------------------------------
$db = reset_world();
install('all-in-one-seo-pack/all_in_one_seo_pack.php', '5.0.2.1');
$GLOBALS['options']['aioseo_options'] = json_encode(['sitemap' => ['general' => ['enable' => false]], 'searchAppearance' => ['global' => ['schema' => ['siteRepresents' => 'organization', 'organizationName' => '#site_title', 'organizationLogo' => '']]]]);
$GLOBALS['options']['aioseo_options_dynamic'] = json_encode(['searchAppearance' => ['postTypes' => [
    'post' => ['show' => true, 'advanced' => ['robotsMeta' => ['default' => false, 'noindex' => true]]],
    'page' => ['show' => false, 'advanced' => ['robotsMeta' => ['default' => true, 'noindex' => false]]],
    'product' => ['show' => true, 'advanced' => ['robotsMeta' => ['default' => true, 'noindex' => false]]],
]]]);
$db->answers = [
    ['/SHOW TABLES LIKE \'wp_aioseo_posts\'/', 'wp_aioseo_posts'],
    ['/a\.robots_default = 0 AND a\.robots_noindex = 1/', [['type' => 'post', 'n' => '2'], ['type' => 'product', 'n' => '1']]],
];
$aioseo = new AioseoReader(new ReadBudget(2.0));
assert($aioseo->supports('5.0.2.1') && $aioseo->supports('4.1.0') && ! $aioseo->supports('4.0.17'), 'aioseo support window');
$read = $aioseo->read($types);
assert($read['noindex'] === ['post' => 2, 'page' => 0, 'product' => 1], 'aioseo noindex from aioseo_posts');
assert($read['type_noindex'] === ['post', 'page'], 'aioseo: custom robots noindex and show=false both close a type');
assert($read['orphans'] === null && $read['sitemap_enabled'] === false && $read['organization_set'] === false && $read['indexing_complete'] === null, 'aioseo: no link counter, sitemap off, organization without logo');

// --- Module orchestration ------------------------------------------------
$db = reset_world();
assert(SeoPluginModule::run() === ['active_seo_plugins' => ['count' => 0, 'slugs' => []], 'unsupported' => 'no_seo_plugin'], 'no SEO plugin → unsupported');

install('wordpress-seo/wp-seo.php', '28.6');
install('wordpress-seo-premium/wp-seo-premium.php', '26.0');
install('seo-by-rank-math/rank-math.php', '1.0.279');
install('wp-seopress/seopress.php', '10.2', false);
$GLOBALS['options']['wpseo'] = ['enable_xml_sitemap' => true, 'enable_text_link_counter' => false, 'indexables_indexing_completed' => true];
$GLOBALS['options']['wpseo_titles'] = ['company_or_person' => 'company', 'company_name' => 'Deneme', 'company_logo' => 'https://example.test/logo.png'];
$db->answers = [
    ['/FROM wp_posts WHERE post_status = \'publish\' AND post_type IN/', [['type' => 'post', 'n' => '8'], ['type' => 'page', 'n' => '3']]],
    ['/SHOW TABLES LIKE \'wp_yoast_indexable\'/', 'wp_yoast_indexable'],
    ['/is_robots_noindex = 1 .*GROUP BY object_sub_type/', [['type' => 'post', 'n' => '2']]],
];
$section = SeoPluginModule::run();
assert($section['active_seo_plugins'] === ['count' => 2, 'slugs' => ['wordpress-seo', 'seo-by-rank-math']], 'premium add-on counts once, inactive plugin not at all: '.json_encode($section['active_seo_plugins']));
assert($section['plugin'] === ['slug' => 'wordpress-seo', 'version' => '28.6', 'supported' => true], 'first active reader wins');
assert($section['post_types'] === [
    ['type' => 'post', 'published' => 8, 'noindex' => 2],
    ['type' => 'page', 'published' => 3, 'noindex' => 0],
    ['type' => 'product', 'published' => 0, 'noindex' => 0],
], 'attachments excluded, every public type listed: '.json_encode($section['post_types']));
assert($section['type_noindex'] === [] && $section['orphans'] === null && $section['sitemap_enabled'] === true && $section['organization_set'] === true && $section['indexing_complete'] === true, 'common shape from the yoast reader');
assert(isset($section['duration_ms']) && $section['budget_exhausted'] === false, 'budget reported');
assert(! isset($section['unsupported']), 'supported run carries no unsupported marker');

// A version outside the reader's window is reported, not guessed.
$db = reset_world();
install('wordpress-seo/wp-seo.php', '31.0');
$section = SeoPluginModule::run();
assert($section['unsupported'] === 'version' && $section['plugin'] === ['slug' => 'wordpress-seo', 'version' => '31.0', 'supported' => false], 'unknown version → unsupported: version');
assert($db->log === [], 'nothing is queried for an unsupported version');

// An exhausted budget turns every count into null and says so.
$db = reset_world();
install('wordpress-seo/wp-seo.php', '28.6');
$section = SeoPluginModule::run(null, new ReadBudget(-1.0));
assert($section['budget_exhausted'] === true && $section['post_types'][0]['published'] === null && $section['post_types'][0]['noindex'] === null && $db->log === [], 'spent budget → null counts, no queries');

// Post type names are bounded: at most 20, each at most 32 characters, slug alphabet only.
assert(count(SeoPluginModule::postTypes()) === 3 && ! in_array('attachment', SeoPluginModule::postTypes(), true), 'post types bounded and media excluded');

fwrite(STDERR, "readers ok\n");
