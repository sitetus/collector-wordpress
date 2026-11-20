<?php

namespace Sitetus\WP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where the core's file-based nonce store lives: a private folder under
 * wp-content/uploads (shared across nodes of a multi-server site), denied
 * to the web by .htaccess (Apache) and an empty index.php (listing).
 * The file name itself is the random one from the pairing code.
 */
final class NonceDirectory
{
    public const FOLDER = 'sitetus';

    /**
     * wp-content/sitetus first (outside the upload folders the file-trace
     * module scans for executables — 27.09: our own index.php guard under
     * uploads/ was reported as a critical finding); the uploads folder only
     * when wp-content itself is not writable.
     */
    public static function path(): string
    {
        $primary = untrailingslashit(WP_CONTENT_DIR).'/'.self::FOLDER;

        if (is_dir($primary) || wp_is_writable(untrailingslashit(WP_CONTENT_DIR))) {
            return $primary;
        }

        $uploads = wp_upload_dir(null, false);
        $base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : WP_CONTENT_DIR.'/uploads';

        return untrailingslashit($base).'/'.self::FOLDER;
    }

    /**
     * Creates the folder with its web-deny rule; true when it exists and is
     * writable. No index.php guard: a PHP file of ours in a scanned folder
     * would look exactly like the executable upload the file-trace module hunts.
     */
    public static function ensure(): bool
    {
        $path = self::path();

        if (! is_dir($path) && ! wp_mkdir_p($path)) {
            return false;
        }

        if (! is_file($path.'/.htaccess')) {
            @file_put_contents($path.'/.htaccess', "Require all denied\nDeny from all\n");
        }

        return wp_is_writable($path);
    }

    /**
     * Removes the nonce store files on unpair/uninstall; the guards stay
     * harmless, the folder is removed when nothing else is in it.
     */
    public static function clear(): void
    {
        $uploads = wp_upload_dir(null, false);
        $uploadBase = isset($uploads['basedir']) ? (string) $uploads['basedir'] : WP_CONTENT_DIR.'/uploads';

        foreach ([untrailingslashit(WP_CONTENT_DIR).'/'.self::FOLDER, untrailingslashit($uploadBase).'/'.self::FOLDER] as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach ((array) glob($path.'/.sitetus-*.json') as $file) {
                wp_delete_file($file);
            }

            foreach (['.htaccess', 'index.php'] as $guard) {
                wp_delete_file($path.'/'.$guard);
            }

            self::removeIfEmpty($path);
        }
    }

    /**
     * Removes the folder with WordPress' direct filesystem class; a folder
     * that still holds something stays. The class is used directly because
     * the folder was made by PHP itself (wp_mkdir_p), so no FTP or SSH
     * credentials are involved on any host.
     */
    private static function removeIfEmpty(string $path): void
    {
        if (! class_exists('WP_Filesystem_Direct', false)) {
            require_once ABSPATH.'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH.'wp-admin/includes/class-wp-filesystem-direct.php';
        }

        (new \WP_Filesystem_Direct(null))->rmdir($path);
    }
}
