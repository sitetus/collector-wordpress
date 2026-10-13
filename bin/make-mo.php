<?php

/**
 * Compiles languages/*.po into .mo without gettext tooling (plain PHP MO
 * writer: untranslated/translated string tables, no plural forms needed
 * by this plugin). Called by build-zip.php; also usable on its own.
 */

$directory = dirname(__DIR__).'/languages';

foreach ((array) glob($directory.'/*.po') as $po) {
    $entries = [];
    $msgid = null;
    $msgstr = null;
    $mode = null;

    $flush = static function () use (&$entries, &$msgid, &$msgstr): void {
        if ($msgid !== null && $msgid !== '' && $msgstr !== null && $msgstr !== '') {
            $entries[$msgid] = $msgstr;
        }

        $msgid = null;
        $msgstr = null;
    };

    foreach (file($po, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^msgid "(.*)"$/', $line, $match) === 1) {
            $flush();
            $msgid = stripcslashes($match[1]);
            $mode = 'id';
        } elseif (preg_match('/^msgstr "(.*)"$/', $line, $match) === 1) {
            $msgstr = stripcslashes($match[1]);
            $mode = 'str';
        } elseif (preg_match('/^"(.*)"$/', $line, $match) === 1) {
            if ($mode === 'id') {
                $msgid .= stripcslashes($match[1]);
            } elseif ($mode === 'str') {
                $msgstr .= stripcslashes($match[1]);
            }
        }
    }

    $flush();
    ksort($entries, SORT_STRING);

    $count = count($entries);
    $originals = '';
    $translations = '';
    $originalTable = '';
    $translationTable = '';
    $headerSize = 7 * 4;
    $tableSize = $count * 8;
    $originalsOffset = $headerSize + $tableSize * 2;

    foreach ($entries as $id => $str) {
        $originalTable .= pack('VV', strlen($id), $originalsOffset + strlen($originals));
        $originals .= $id."\0";
    }

    $translationsOffset = $originalsOffset + strlen($originals);

    foreach ($entries as $id => $str) {
        $translationTable .= pack('VV', strlen($str), $translationsOffset + strlen($translations));
        $translations .= $str."\0";
    }

    $mo = pack('VVVVVVV', 0x950412de, 0, $count, $headerSize, $headerSize + $tableSize, 0, 0)
        .$originalTable.$translationTable.$originals.$translations;

    $target = preg_replace('/\.po$/', '.mo', $po);
    file_put_contents($target, $mo);

    echo 'Compiled '.basename($target)." ({$count} strings)\n";
}
