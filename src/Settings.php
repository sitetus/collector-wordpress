<?php

namespace Sitetus\WP;

use Sitetus\Collector\Config;

/**
 * The paired identity, stored in wp_options (autoload off): exactly the
 * values a pairing code carries — public key, project key, algorithm,
 * expiry, nonce file name, enabled modules. No secret is ever stored.
 */
final class Settings
{
    public const MODULES = ['files', 'archives', 'permissions', 'bot_traffic', SeoPluginModule::ID, MailDeliveryModule::ID, CachePostureModule::ID, SecurityPluginModule::ID, CoreIntegrityModule::ID];

    /**
     * Modules implemented in this plugin rather than in the collector core:
     * always offered on the settings page (opt-in), whatever the pairing
     * code's module list says — the panel's list describes the core.
     */
    public const PLUGIN_MODULES = [SeoPluginModule::ID, MailDeliveryModule::ID, CachePostureModule::ID, SecurityPluginModule::ID, CoreIntegrityModule::ID];

    /**
     * @return array<string, mixed>|null null while the site is not paired
     */
    public static function get(): ?array
    {
        $value = get_option(Plugin::OPTION, null);

        if (! is_array($value) || ($value['project_key'] ?? '') === '' || ($value['public_key'] ?? '') === '') {
            return null;
        }

        $value['modules_allowed'] = self::withPluginModules((array) ($value['modules_allowed'] ?? []));

        return $value;
    }

    public static function isPaired(): bool
    {
        return self::get() !== null;
    }

    /**
     * @param array<string, mixed> $values validated pairing payload
     * @param array<int, string> $modules the owner's (sub)selection of the code's modules
     */
    public static function pair(array $values, array $modules): void
    {
        $allowed = self::withPluginModules(array_values(array_intersect(self::MODULES, (array) ($values['modules'] ?? []))));
        $enabled = array_values(array_intersect($allowed, $modules));

        update_option(Plugin::OPTION, [
            'project_key' => (string) $values['project_key'],
            'public_key' => (string) $values['public_key'],
            'algo' => (string) $values['algo'],
            'expires_at' => isset($values['expires_at']) && is_numeric($values['expires_at']) ? (int) $values['expires_at'] : null,
            'nonce_file' => (string) $values['nonce_file'],
            'modules_allowed' => $allowed,
            'modules' => $enabled,
            'panel' => (string) ($values['panel'] ?? ''),
            'site' => (string) ($values['site'] ?? ''),
            'paired_at' => time(),
        ], false);

        delete_option(Plugin::OPTION_LAST_PULL);
    }

    /**
     * @param array<int, string> $modules
     */
    public static function updateModules(array $modules): void
    {
        $current = self::get();

        if ($current === null) {
            return;
        }

        $current['modules'] = array_values(array_intersect((array) ($current['modules_allowed'] ?? []), $modules));

        update_option(Plugin::OPTION, $current, false);
    }

    /**
     * @param array<int, string> $allowed
     * @return array<int, string>
     */
    private static function withPluginModules(array $allowed): array
    {
        return array_values(array_unique(array_merge(array_values(array_filter($allowed, 'is_string')), self::PLUGIN_MODULES)));
    }

    public static function unpair(): void
    {
        delete_option(Plugin::OPTION);
        delete_option(Plugin::OPTION_LAST_PULL);
        NonceDirectory::clear();
    }

    /**
     * The core's configuration object for the paired identity. The project
     * root is WordPress' ABSPATH — never discovered by walking directories.
     */
    public static function collectorConfig(): ?Config
    {
        $settings = self::get();

        if ($settings === null) {
            return null;
        }

        $config = Config::fromArray([
            'project_key' => $settings['project_key'],
            'public_key' => $settings['public_key'],
            'algo' => $settings['algo'],
            'expires_at' => $settings['expires_at'] ?? '',
            'root_override' => untrailingslashit(ABSPATH),
            'nonce_file' => $settings['nonce_file'],
            'modules' => (array) ($settings['modules'] ?? []),
        ], NonceDirectory::path());

        $config->collectorName = 'sitetus-wp';

        return $config;
    }

    public static function recordPull(int $status): void
    {
        update_option(Plugin::OPTION_LAST_PULL, ['at' => time(), 'status' => $status], false);
    }

    /**
     * @return array{at: int, status: int}|null
     */
    public static function lastPull(): ?array
    {
        $value = get_option(Plugin::OPTION_LAST_PULL, null);

        return is_array($value) && isset($value['at'], $value['status']) ? ['at' => (int) $value['at'], 'status' => (int) $value['status']] : null;
    }
}
