<?php

namespace Sitetus\WP;

use Sitetus\Collector\Envelope;
use Sitetus\Collector\Health;
use Sitetus\Collector\Inventory;
use Sitetus\Collector\Modules\ModuleRunner;
use Sitetus\Collector\NonceStore;
use Sitetus\Collector\RequestVerifier;

/**
 * The plugin's counterpart of the core's Collector::run(): verify the
 * request with the core's verifier, then build the same envelope — with
 * WordPress' own facts (plugin inventory, core/plugin/theme update state,
 * configuration flags) in place of the Laravel bootstrap. The envelope
 * shape is the core's; only `collector.name` says `sitetus-wp`.
 */
final class Runner
{
    /**
     * Core Level 1 checks that describe the host rather than a Laravel tree.
     *
     * @var array<int, string>
     */
    public const HOST_CHECK_IDS = ['disk_usage', 'origin_tls_expiry', 'php_ini', 'listening_ports', 'opcache', 'load'];

    /**
     * @param array<string, string> $headers lower-cased header names
     * @return array{status: int, headers: array<string, string>, body: array<string, mixed>|null}
     */
    public static function run(string $method, string $path, array $headers, string $body, int $now): array
    {
        $config = Settings::collectorConfig();

        if ($config === null || ! NonceDirectory::ensure()) {
            return ['status' => 404, 'headers' => [], 'body' => null];
        }

        $startedAt = microtime(true);
        $runtimeIni = Envelope::runtimeIniSnapshot();

        $verifier = new RequestVerifier($config, new NonceStore($config->noncePath()));
        list($status, $extraHeaders) = $verifier->verify($method, $path, $headers, $body, $now);

        if ($status !== 200) {
            return ['status' => $status, 'headers' => $extraHeaders, 'body' => null];
        }

        $root = untrailingslashit(ABSPATH);
        $inventory = new Inventory($config);
        $inventory->rootPath();
        $health = new Health();

        // The core's Level 1 list is written for Laravel roots (storage/, bootstrap/cache/,
        // .env, heartbeats); on WordPress those would read as "missing" and mislead. Only the
        // host-level checks are kept; WordPress' own checks take their place.
        $checks = array_merge(
            array_values(array_filter($health->level1Checks($root), static function (array $check): bool {
                return in_array($check['id'] ?? '', self::HOST_CHECK_IDS, true);
            })),
            WordPressChecks::all()
        );
        $framework = [
            'detected' => 'wordpress',
            'name' => 'WordPress',
            'version' => (string) get_bloginfo('version'),
            'detection_method' => 'plugin',
        ];

        $modules = (new ModuleRunner($config))->run($root, $framework, $now);

        // The seo_plugin module is this plugin's own (not in the shared core), so it
        // joins the core's modules section here under the same id discipline.
        if (in_array(SeoPluginModule::ID, $config->modules, true)) {
            $modules = $modules ?? ['enabled' => [], 'web_root' => '.'];
            $modules['enabled'][] = SeoPluginModule::ID;

            try {
                $modules[SeoPluginModule::ID] = SeoPluginModule::run();
            } catch (\Throwable $exception) {
                $modules[SeoPluginModule::ID] = ['error' => 'msg.module_failed'];
            }
        }

        // 0.4.0: mail_delivery and cache_posture are plugin-native too.
        if (in_array(MailDeliveryModule::ID, $config->modules, true)) {
            $modules = $modules ?? ['enabled' => [], 'web_root' => '.'];
            $modules['enabled'][] = MailDeliveryModule::ID;

            try {
                $modules[MailDeliveryModule::ID] = MailDeliveryModule::run();
            } catch (\Throwable $exception) {
                $modules[MailDeliveryModule::ID] = ['error' => 'msg.module_failed'];
            }
        }

        if (in_array(CachePostureModule::ID, $config->modules, true)) {
            $modules = $modules ?? ['enabled' => [], 'web_root' => '.'];
            $modules['enabled'][] = CachePostureModule::ID;

            try {
                $modules[CachePostureModule::ID] = CachePostureModule::run();
            } catch (\Throwable $exception) {
                $modules[CachePostureModule::ID] = ['error' => 'msg.module_failed'];
            }
        }

        if (in_array(SecurityPluginModule::ID, $config->modules, true)) {
            $modules = $modules ?? ['enabled' => [], 'web_root' => '.'];
            $modules['enabled'][] = SecurityPluginModule::ID;

            try {
                $modules[SecurityPluginModule::ID] = SecurityPluginModule::run();
            } catch (\Throwable $exception) {
                $modules[SecurityPluginModule::ID] = ['error' => 'msg.module_failed'];
            }
        }

        // 0.5.0: core_integrity compares the core files with WordPress.org's official list.
        if (in_array(CoreIntegrityModule::ID, $config->modules, true)) {
            $modules = $modules ?? ['enabled' => [], 'web_root' => '.'];
            $modules['enabled'][] = CoreIntegrityModule::ID;

            try {
                $modules[CoreIntegrityModule::ID] = CoreIntegrityModule::run(null, $now);
            } catch (\Throwable $exception) {
                $modules[CoreIntegrityModule::ID] = ['error' => 'msg.module_failed'];
            }
        }

        $envelope = (new Envelope($config))->build(
            2,
            $framework,
            WordPressChecks::pluginInventory(),
            $checks,
            null,
            $inventory->rootPathSource(),
            [],
            $startedAt,
            SITETUS_PLUGIN_FILE,
            [],
            $modules,
            $runtimeIni
        );

        return ['status' => 200, 'headers' => [], 'body' => $envelope];
    }
}
