<?php
/**
 * PSR-4 autoloader for the plugin (no Composer in the distributed zip):
 * Sitetus\Collector\* → includes/collector (the vendored core, unchanged),
 * Sitetus\WP\*        → src.
 */

if (! defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Sitetus\\Collector\\' => __DIR__.'/collector/',
        'Sitetus\\WP\\' => dirname(__DIR__).'/src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (strpos($class, $prefix) !== 0) {
            continue;
        }

        $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
