<?php

/**
 * Builds the installable zip (dist/sitetus.zip) with a single top-level
 * folder `sitetus/`, honouring .distignore. Compiles the .mo files first.
 *
 * Usage: php bin/build-zip.php [output]
 */

$root = dirname(__DIR__);
$output = $argv[1] ?? $root.'/dist/sitetus.zip';

require __DIR__.'/make-mo.php';

$ignore = array_values(array_filter(array_map('trim', file($root.'/.distignore') ?: []), static fn (string $line): bool => $line !== '' && $line[0] !== '#'));

$isIgnored = static function (string $relative) use ($ignore): bool {
    foreach ($ignore as $pattern) {
        $pattern = trim($pattern, '/');

        if ($relative === $pattern || strpos($relative, $pattern.'/') === 0 || fnmatch($pattern, $relative)) {
            return true;
        }
    }

    return false;
};

if (! is_dir(dirname($output))) {
    mkdir(dirname($output), 0775, true);
}

if (is_file($output)) {
    unlink($output);
}

$zip = new ZipArchive;

if ($zip->open($output, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot open {$output}\n");
    exit(1);
}

$count = 0;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

    if ($isIgnored($relative)) {
        continue;
    }

    if ($file->isDir()) {
        $zip->addEmptyDir('sitetus/'.$relative);
        continue;
    }

    $zip->addFile($file->getPathname(), 'sitetus/'.$relative);
    $count++;
}

$zip->close();

echo "Built {$output} ({$count} files, ".filesize($output)." bytes)\n";
echo 'SHA-256: '.hash_file('sha256', $output)."\n";
