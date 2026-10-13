<?php

namespace Sitetus\WP;

use Sitetus\WP\Readers\ReadBudget;
use Sitetus\WP\Readers\Security\AiosReader;
use Sitetus\WP\Readers\Security\KadenceSecurityReader;
use Sitetus\WP\Readers\Security\LimitLoginAttemptsReader;
use Sitetus\WP\Readers\Security\ReallySimpleSecurityReader;
use Sitetus\WP\Readers\Security\SecurityPluginReader;
use Sitetus\WP\Readers\Security\TwoFactorReader;
use Sitetus\WP\Readers\Security\WordfenceReader;

/**
 * The opt-in `security_plugin` module (0.4.0): reads what the site's own
 * security plugins keep — their scanner's open findings and last run,
 * the firewall mode, administrator two-factor coverage, hardening
 * switches, login pressure counters — and answers in one
 * plugin-independent shape. Unlike the SEO module, several readers may
 * be active at once (a firewall next to a login limiter next to a 2FA
 * plugin), so every supported reader runs and each block comes from the
 * first reader, in READERS order, that keeps it; the block names that
 * reader in `plugin`. Only counts, booleans, mode names and plugin slugs
 * leave: never an IP address, a user name, a path, a finding text or a
 * key. The verdicts (open findings, learning mode, uncovered
 * administrators, "setting on but still exposed", conflicting
 * firewalls) are the panel's.
 *
 * Lives in the WordPress plugin, not in the shared collector core, so it
 * is always offered on the settings page (opt-in, off by default).
 */
final class SecurityPluginModule
{
    public const ID = 'security_plugin';

    public const BUDGET_SECONDS = 2.0;

    public const MAX_PLUGINS = 10;

    public const UNSUPPORTED_NONE = 'no_security_plugin';

    public const UNSUPPORTED_VERSION = 'version';

    /**
     * @var array<int, string>
     */
    public const BLOCKS = ['scan', 'firewall', 'two_factor', 'hardening', 'login_pressure'];

    /**
     * Reader classes in preference order: a block kept by several plugins
     * is taken from the first one here.
     *
     * @var array<int, class-string<SecurityPluginReader>>
     */
    public const READERS = [
        WordfenceReader::class,
        KadenceSecurityReader::class,
        AiosReader::class,
        ReallySimpleSecurityReader::class,
        LimitLoginAttemptsReader::class,
        TwoFactorReader::class,
    ];

    /**
     * Plugin slugs that act as a site's security plugin, grouped by product
     * so a premium add-on next to its free base counts once.
     *
     * @var array<string, string>
     */
    public const FAMILIES = [
        'wordfence' => 'wordfence',
        'wordfence-login-security' => 'wordfence',
        'better-wp-security' => 'better-wp-security',
        'ithemes-security-pro' => 'better-wp-security',
        'solid-security-pro' => 'better-wp-security',
        'all-in-one-wp-security-and-firewall' => 'all-in-one-wp-security-and-firewall',
        'all-in-one-wp-security-and-firewall-premium' => 'all-in-one-wp-security-and-firewall',
        'really-simple-ssl' => 'really-simple-ssl',
        'really-simple-ssl-pro' => 'really-simple-ssl',
        'limit-login-attempts-reloaded' => 'limit-login-attempts-reloaded',
        'two-factor' => 'two-factor',
        'wp-2fa' => 'wp-2fa',
        'wp-2fa-premium' => 'wp-2fa',
        'sg-security' => 'sg-security',
        'wp-simple-firewall' => 'wp-simple-firewall',
        'defender-security' => 'defender-security',
        'jetpack-protect' => 'jetpack-protect',
        'ninjafirewall' => 'ninjafirewall',
        'sucuri-scanner' => 'sucuri-scanner',
        'loginizer' => 'loginizer',
        'loginizer-security' => 'loginizer',
        'login-lockdown' => 'login-lockdown',
        'wp-cerber' => 'wp-cerber',
        'patchstack' => 'patchstack',
        'malcare-security' => 'malcare-security',
        'miniorange-2-factor-authentication' => 'miniorange-2-factor-authentication',
    ];

    /**
     * What a family does when no reader can ask it: whether it ships a
     * firewall and whether it limits logins. A reader's `roles` answer
     * (what is actually switched on) replaces these for its own family.
     *
     * @var array<string, array{firewall: bool, login_limiter: bool}>
     */
    public const FAMILY_ROLES = [
        'wordfence' => ['firewall' => true, 'login_limiter' => true],
        'better-wp-security' => ['firewall' => true, 'login_limiter' => true],
        'all-in-one-wp-security-and-firewall' => ['firewall' => true, 'login_limiter' => true],
        'really-simple-ssl' => ['firewall' => false, 'login_limiter' => false],
        'limit-login-attempts-reloaded' => ['firewall' => false, 'login_limiter' => true],
        'two-factor' => ['firewall' => false, 'login_limiter' => false],
        'wp-2fa' => ['firewall' => false, 'login_limiter' => false],
        'sg-security' => ['firewall' => false, 'login_limiter' => true],
        'wp-simple-firewall' => ['firewall' => true, 'login_limiter' => true],
        'defender-security' => ['firewall' => true, 'login_limiter' => true],
        'jetpack-protect' => ['firewall' => true, 'login_limiter' => false],
        'ninjafirewall' => ['firewall' => true, 'login_limiter' => false],
        'sucuri-scanner' => ['firewall' => false, 'login_limiter' => false],
        'loginizer' => ['firewall' => false, 'login_limiter' => true],
        'login-lockdown' => ['firewall' => false, 'login_limiter' => true],
        'wp-cerber' => ['firewall' => true, 'login_limiter' => true],
        'patchstack' => ['firewall' => true, 'login_limiter' => false],
        'malcare-security' => ['firewall' => true, 'login_limiter' => false],
        'miniorange-2-factor-authentication' => ['firewall' => false, 'login_limiter' => false],
    ];

    /**
     * @param array<int, SecurityPluginReader>|null $readers stand-ins in tests; null builds the real ones
     * @return array<string, mixed>
     */
    public static function run(?array $readers = null, ?ReadBudget $budget = null): array
    {
        $budget = $budget ?? new ReadBudget(self::BUDGET_SECONDS);
        $readers = $readers ?? self::readers($budget);
        $activeFamilies = self::activeSecurityPlugins();
        $section = ['active_security_plugins' => ['count' => count($activeFamilies), 'slugs' => $activeFamilies]];

        $plugins = [];
        $reads = [];

        foreach ($readers as $reader) {
            if (! $reader->isActive() || count($plugins) >= self::MAX_PLUGINS) {
                continue;
            }

            $version = $reader->version();
            $supported = $version !== null && $reader->supports($version);
            $plugins[] = ['slug' => $reader->slug(), 'version' => $version ?? '0', 'supported' => $supported];

            if ($supported) {
                $reads[$reader->slug()] = $reader->read();
            }
        }

        if ($plugins === []) {
            return $section + ['conflicts' => self::conflicts($activeFamilies, []), 'unsupported' => self::UNSUPPORTED_NONE];
        }

        $section['plugins'] = $plugins;

        if ($reads === []) {
            return $section + ['conflicts' => self::conflicts($activeFamilies, []), 'unsupported' => self::UNSUPPORTED_VERSION];
        }

        foreach (self::BLOCKS as $block) {
            $section[$block] = null;

            foreach ($reads as $slug => $read) {
                if (is_array($read[$block] ?? null)) {
                    $section[$block] = ['plugin' => $slug] + $read[$block];
                    break;
                }
            }
        }

        return $section + [
            'conflicts' => self::conflicts($activeFamilies, $reads),
            'duration_ms' => $budget->elapsedMs(),
            'budget_exhausted' => $budget->exhausted(),
        ];
    }

    /**
     * @return array<int, SecurityPluginReader>
     */
    public static function readers(ReadBudget $budget): array
    {
        return array_map(static function (string $class) use ($budget): SecurityPluginReader {
            return new $class($budget);
        }, self::READERS);
    }

    /**
     * Distinct active security plugin products, by base slug.
     *
     * @return array<int, string>
     */
    public static function activeSecurityPlugins(): array
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
     * How many active families run a firewall and how many limit logins —
     * from the reader's own answer where one ran, from FAMILY_ROLES
     * otherwise.
     *
     * @param array<int, string> $families
     * @param array<string, array<string, mixed>> $reads reader slug => read
     * @return array{firewalls: int, login_limiters: int}
     */
    private static function conflicts(array $families, array $reads): array
    {
        $firewalls = 0;
        $limiters = 0;

        foreach ($families as $family) {
            $roles = $reads[$family]['roles'] ?? (self::FAMILY_ROLES[$family] ?? ['firewall' => false, 'login_limiter' => false]);
            $firewalls += ! empty($roles['firewall']) ? 1 : 0;
            $limiters += ! empty($roles['login_limiter']) ? 1 : 0;
        }

        return ['firewalls' => $firewalls, 'login_limiters' => $limiters];
    }
}
