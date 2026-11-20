<?php

/**
 * Copies the collector core (sitetus/collector src/) into includes/collector/
 * — same namespaces, same files, same code — so the plugin answers with the
 * exact envelope the single file and the Composer wrapper produce.
 *
 * One line group is added to each copy: the direct-access guard the
 * WordPress.org plugin directory asks of every PHP file. The core itself
 * runs outside WordPress and cannot carry it.
 *
 * Usage: php bin/sync-core.php [path-to-sitetus-collector]
 */

const SITETUS_DIRECT_ACCESS_GUARD = "\n\nif (! defined('ABSPATH')) {\n    exit;\n}";

$source = rtrim($argv[1] ?? dirname(__DIR__, 2).'/sitetus-collector', '/\\');
$target = dirname(__DIR__).'/includes/collector';

if (! is_dir($source.'/src')) {
    fwrite(STDERR, "Collector source not found: {$source}/src\n");
    exit(1);
}

$files = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source.'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        $files[] = substr($file->getPathname(), strlen($source.'/src') + 1);
    }
}

sort($files);

foreach ($files as $relative) {
    $destination = $target.'/'.str_replace('\\', '/', $relative);

    if (! is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0775, true);
    }

    $code = str_replace("\r\n", "\n", (string) file_get_contents($source.'/src/'.$relative));
    $guarded = preg_replace('/^(namespace [^;]+;)$/m', '$1'.SITETUS_DIRECT_ACCESS_GUARD, $code, 1, $count);

    if ($count !== 1 || ! is_string($guarded)) {
        fwrite(STDERR, "No namespace line to put the guard after: {$relative}\n");
        exit(1);
    }

    file_put_contents($destination, $guarded);
}

$version = 'unknown';

if (preg_match("/VERSION = '([^']+)'/", (string) file_get_contents($source.'/src/Config.php'), $match) === 1) {
    $version = $match[1];
}

file_put_contents($target.'/CORE_VERSION', $version."\n");

echo 'Synced '.count($files)." core files (sitetus/collector {$version}) into includes/collector\n";
