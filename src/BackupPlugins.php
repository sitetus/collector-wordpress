<?php

namespace Sitetus\WP;

/**
 * Backup plugins the wp_backup_plugin row looks for (panel decision 9,
 * 03.10.2026): the site only reports whether one of them is active and
 * which — nothing about its schedule or its backups. The list is the
 * owner's to extend; the panel catalog mirrors it so the owner can see
 * what is checked, while the user-facing text never names the list.
 *
 * Slugs are wp-content/plugins directory names. Those marked "directory"
 * were verified against the wordpress.org plugin API on 03.10.2026 with
 * their active-install counts; the two paid products are the directory
 * names their vendors ship.
 */
final class BackupPlugins
{
    /**
     * @var array<int, string>
     */
    public const SLUGS = [
        'all-in-one-wp-migration',   // directory, 5M
        'updraftplus',               // directory, 4M
        'jetpack',                   // directory, 3M (VaultPress Backup is a paid Jetpack product)
        'duplicator',                // directory, 1M
        'wpvivid-backuprestore',     // directory, 900k
        'backwpup',                  // directory, 400k
        'backup',                    // directory, 200k (JetBackup)
        'migrate-guru',              // directory, 200k
        'wp-staging',                // directory, 100k
        'blogvault-real-time-backup', // directory, 80k
        'backup-backup',             // directory, 80k (Backup Migration)
        'boldgrid-backup',           // directory, 50k (Total Upkeep)
        'jetpack-backup',            // directory, 20k
        'wp-database-backup',        // directory, 20k
        'prime-mover',               // directory, 10k
        'xcloner-backup-and-restore', // directory, 10k
        'vaultpress',                // directory, 10k
        'wp-time-capsule',           // directory, 10k
        'everest-backup',            // directory, 3k
        'backupbuddy',               // paid (iThemes BackupBuddy, legacy directory name)
        'solid-backups',             // paid (SolidWP, successor of BackupBuddy)
    ];
}
