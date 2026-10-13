<?php

namespace Sitetus\WP\Readers\Mail;

use Sitetus\WP\Readers\ReadsPluginStorage;

/**
 * Shared ground of the mail readers: the generic plugin-storage helpers
 * plus a seven-day count over a log table, judged by the database's own
 * clock so a server timezone never shifts the window.
 */
abstract class AbstractMailReader implements MailReader
{
    use ReadsPluginStorage;

    public const WINDOW_DAYS = 7;

    public const TRANSPORT_PHP_MAIL = 'php_mail';

    public const TRANSPORT_SMTP = 'smtp';

    public const TRANSPORT_API = 'api';

    /**
     * Rows of a log table within the window that match a fixed condition.
     * The condition is a literal written by the reader (never user input);
     * the table name comes from $wpdb->prefix.
     */
    protected function countWithin(string $table, string $condition): ?int
    {
        return $this->count("SELECT COUNT(*) FROM {$table} WHERE {$condition} AND created_at >= DATE_SUB(NOW(), INTERVAL ".self::WINDOW_DAYS.' DAY)');
    }
}
