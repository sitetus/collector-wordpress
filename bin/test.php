<?php

/**
 * Runs every tests/*.php file, each in its own PHP process with assertions
 * switched on (the flags CI uses), and stops at the first failure. The
 * files are discovered, never listed: a new test file runs here and in CI
 * without anyone remembering to add it.
 *
 * Usage: php bin/test.php [--envelope=path]
 *
 * tests/smoke.php prints the collector envelope on stdout; it is checked to
 * be a JSON object and, with --envelope, saved to that path (the panel's
 * EnvelopeValidator is run against it from the panel repo). Everything else
 * a test prints (stdout and stderr) is passed through on stdout, in order.
 */

$root = dirname(__DIR__);
$envelopeTest = 'smoke.php';
$envelopePath = null;

foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--envelope=') === 0 && strlen($argument) > 11) {
        $envelopePath = substr($argument, 11);
        continue;
    }

    fwrite(STDERR, "Unknown argument: {$argument}\nUsage: php bin/test.php [--envelope=path]\n");
    exit(2);
}

$files = (array) glob($root.'/tests/*.php');
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "No test file found under tests/.\n");
    exit(1);
}

echo 'PHP '.PHP_VERSION.' — '.count($files)." test file(s)\n";

$started = microtime(true);

foreach ($files as $index => $file) {
    $name = basename($file);
    $label = sprintf('[%d/%d] tests/%s', $index + 1, count($files), $name);
    $fileStarted = microtime(true);

    // The child's stderr goes through a temporary file: one pipe is read at a
    // time (no deadlock on a large envelope) and the lines stay in order.
    $stderrFile = (string) tempnam(sys_get_temp_dir(), 'sitetus-test-');

    $process = proc_open(
        [PHP_BINARY, '-d', 'zend.assertions=1', '-d', 'assert.exception=1', $file],
        [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']],
        $pipes,
        $root
    );

    if (! is_resource($process)) {
        @unlink($stderrFile);
        echo "{$label} — could not start PHP\n";
        exit(1);
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    $stderr = (string) file_get_contents($stderrFile);
    @unlink($stderrFile);

    if ($exitCode === 0 && $name === $envelopeTest) {
        if (! is_array(json_decode($stdout, true))) {
            echo "{$label} — stdout is not a JSON envelope\n";
            $exitCode = 1;
        } elseif ($envelopePath !== null && file_put_contents($envelopePath, $stdout) === false) {
            echo "{$label} — cannot write {$envelopePath}\n";
            $exitCode = 1;
        }
    } elseif ($stdout !== '') {
        echo rtrim($stdout)."\n";
    }

    if ($stderr !== '') {
        echo rtrim($stderr)."\n";
    }

    if ($exitCode !== 0) {
        echo "{$label} — FAILED (exit {$exitCode})\n";
        exit($exitCode > 0 && $exitCode < 255 ? $exitCode : 1);
    }

    echo sprintf("%s — ok (%.1fs)%s\n", $label, microtime(true) - $fileStarted, $name === $envelopeTest && $envelopePath !== null ? ", envelope → {$envelopePath}" : '');
}

echo sprintf("All %d test file(s) passed (%.1fs)\n", count($files), microtime(true) - $started);
