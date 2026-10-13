<?php
/**
 * Runs when the plugin is deleted from WP admin. Everything the plugin ever
 * wrote goes: its configuration option (the pairing), its last-pull option
 * and the replay-protection record with the folder that held it. On a
 * multisite network every site is cleaned, since each one pairs on its own.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$sitetusCleanUp = static function (): void {
    delete_option('sitetus_collector');
    delete_option('sitetus_last_pull');

    $uploads = wp_upload_dir(null, false);
    $uploadBase = isset($uploads['basedir']) ? (string) $uploads['basedir'] : WP_CONTENT_DIR.'/uploads';

    foreach ([untrailingslashit(WP_CONTENT_DIR).'/sitetus', untrailingslashit($uploadBase).'/sitetus'] as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        foreach ((array) glob($directory.'/.sitetus-*.json') as $file) {
            @unlink($file);
        }

        foreach (['.htaccess', 'index.php'] as $guard) {
            @unlink($directory.'/'.$guard);
        }

        @rmdir($directory);
    }
};

if (function_exists('is_multisite') && is_multisite()) {
    foreach ((array) get_sites(['fields' => 'ids', 'number' => 0]) as $sitetusSiteId) {
        switch_to_blog((int) $sitetusSiteId);
        $sitetusCleanUp();
        restore_current_blog();
    }
} else {
    $sitetusCleanUp();
}

unset($sitetusCleanUp, $sitetusSiteId);
