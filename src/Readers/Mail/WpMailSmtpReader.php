<?php

namespace Sitetus\WP\Readers\Mail;

/**
 * WP Mail SMTP (wp-mail-smtp; the Pro build lives in wp-mail-smtp-pro
 * with the same option and constant). Storage verified on 4.10.0
 * (03.10.2026):
 *  - option wp_mail_smtp: mail.mailer (default "mail" = PHP mail();
 *    "smtp"/"pepipost" are SMTP hosts, every other id an API mailer),
 *    one group per mailer with its own keys; a WPMS_ON + WPMS_MAILER
 *    constant pair overrides the option. logs.enabled is the Pro email
 *    log switch (absent in Lite).
 *  - table {prefix}wpmailsmtp_debug_events (event_type 0 = error,
 *    1 = debug; created_at), created by the plugin's migration on the
 *    first admin request, so it may not exist yet on a fresh install.
 *  - the Pro email log table {prefix}wpmailsmtp_emails_log (status 1 =
 *    sent, date_sent) is counted only when it exists.
 * Only the mailer's class and counts leave; no host, key or address.
 */
final class WpMailSmtpReader extends AbstractMailReader
{
    public const PRO_PLUGIN_FILE = 'wp-mail-smtp-pro/wp_mail_smtp.php';

    public const SMTP_MAILERS = ['smtp', 'pepipost'];

    public function slug(): string
    {
        return 'wp-mail-smtp';
    }

    protected function pluginFile(): string
    {
        return 'wp-mail-smtp/wp_mail_smtp.php';
    }

    protected function versionConstant(): string
    {
        return 'WPMS_PLUGIN_VER';
    }

    protected function minVersion(): string
    {
        return '3.0';
    }

    protected function maxVersionExclusive(): string
    {
        return '5.0';
    }

    public function isActive(): bool
    {
        return function_exists('is_plugin_active') && (is_plugin_active($this->pluginFile()) || is_plugin_active(self::PRO_PLUGIN_FILE));
    }

    public function read(): array
    {
        $options = $this->optionArray('wp_mail_smtp');
        $mailer = self::path($options, ['mail', 'mailer']);
        $mailer = is_string($mailer) && $mailer !== '' ? strtolower($mailer) : 'mail';

        if (defined('WPMS_ON') && constant('WPMS_ON') === true && defined('WPMS_MAILER') && is_string(constant('WPMS_MAILER')) && constant('WPMS_MAILER') !== '') {
            $mailer = strtolower((string) constant('WPMS_MAILER'));
        }

        if ($mailer === 'mail') {
            $transport = self::TRANSPORT_PHP_MAIL;
            $configured = false;
        } else {
            $transport = in_array($mailer, self::SMTP_MAILERS, true) ? self::TRANSPORT_SMTP : self::TRANSPORT_API;
            $group = is_array($options[$mailer] ?? null) ? $options[$mailer] : [];
            $configured = $this->hasAnyValue($group) || (defined('WPMS_ON') && constant('WPMS_ON') === true);
        }

        $errors = null;
        $events = $this->table('wpmailsmtp_debug_events');

        if ($this->tableExists($events)) {
            $errors = $this->countWithin($events, 'event_type = 0');
        }

        $sent = null;
        $log = $this->table('wpmailsmtp_emails_log');

        if ($this->tableExists($log)) {
            $sent = $this->count("SELECT COUNT(*) FROM {$log} WHERE status = 1 AND date_sent >= DATE_SUB(NOW(), INTERVAL ".self::WINDOW_DAYS.' DAY)');
        }

        return [
            'transport' => $transport,
            'provider_configured' => $configured,
            'errors_7d' => $errors,
            'sent_7d' => $sent,
            'logging_enabled' => (bool) (self::path($options, ['logs', 'enabled']) ?? false),
        ];
    }

    /**
     * Whether any setting in the mailer's group is filled; values are never
     * read beyond "empty or not".
     *
     * @param array<string, mixed> $group
     */
    private function hasAnyValue(array $group): bool
    {
        foreach ($group as $value) {
            if ((is_string($value) && trim($value) !== '') || (is_array($value) && $value !== []) || is_int($value)) {
                return true;
            }
        }

        return false;
    }
}
