<?php

/**
 * Copies the collector core (sitetus/collector src/) into includes/collector/
 * — same namespaces, same files, same code — so the plugin answers with the
 * exact envelope the single file and the Composer wrapper produce.
 *
 * Two things differ from the core, both for the WordPress.org plugin
 * directory. Each copy gets the direct-access guard asked of every PHP
 * file (the core runs outside WordPress and cannot carry it), and, where
 * the core has to use a PHP function the coding standard reserves, one
 * line that names those rules with the reason. The two files the plugin
 * never loads are not copied: the standalone request front and the
 * Laravel bootstrap.
 *
 * Usage: php bin/sync-core.php [path-to-sitetus-collector]
 */

const SITETUS_DIRECT_ACCESS_GUARD = "\n\nif (! defined('ABSPATH')) {\n    exit;\n}";

const SITETUS_NOT_SHIPPED = ['Collector.php', 'Level2.php'];

const SITETUS_FILE_READS = 'WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose';

const SITETUS_STANDARD_NOTES = [
    'NonceStore.php' => 'WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Replay protection needs one file handle held under flock() from read to write; WP_Filesystem has no locking.',
    'Modules/FileTrace.php' => SITETUS_FILE_READS.' -- Reads only the first and last bytes of a file, read-only; WP_Filesystem can only load a whole file into memory.',
    'Modules/BotTraffic.php' => SITETUS_FILE_READS.' -- Reads the tail of an access log line by line, read-only; WP_Filesystem can only load a whole file into memory.',
    'Health.php' => 'WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- This file is shared with the collectors that run outside WordPress. The two server values are only compared (a path prefix, a certificate name) and are never stored or printed.',
];

$source = rtrim($argv[1] ?? dirname(__DIR__, 2).'/sitetus-collector', '/\\');
$target = dirname(__DIR__).'/includes/collector';

if (! is_dir($source.'/src')) {
    fwrite(STDERR, "Collector source not found: {$source}/src\n");
    exit(1);
}

$files = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source.'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source.'/src') + 1));

    if ($file->getExtension() === 'php' && ! in_array($relative, SITETUS_NOT_SHIPPED, true)) {
        $files[] = $relative;
    }
}

sort($files);

foreach (SITETUS_NOT_SHIPPED as $relative) {
    if (is_file($target.'/'.$relative)) {
        unlink($target.'/'.$relative);
    }
}

foreach ($files as $relative) {
    $destination = $target.'/'.$relative;
    $guard = SITETUS_DIRECT_ACCESS_GUARD.(isset(SITETUS_STANDARD_NOTES[$relative]) ? "\n\n// phpcs:disable ".SITETUS_STANDARD_NOTES[$relative] : '');

    if (! is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0775, true);
    }

    $code = str_replace("\r\n", "\n", (string) file_get_contents($source.'/src/'.$relative));
    $guarded = preg_replace('/^(namespace [^;]+;)$/m', '$1'.$guard, $code, 1, $count);

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
