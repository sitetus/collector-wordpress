<?php

namespace Sitetus\WP\Readers\Mail;

/**
 * FluentSMTP (fluent-smtp). Storage verified on 2.4.1 (03.10.2026):
 *  - option fluentmail-settings (absent until the first connection is
 *    saved): connections{key: {provider_settings: {provider, …}}},
 *    mappings{sender → key}, misc{default_connection, log_emails yes|no,
 *    log_saved_interval_days}. Provider keys: smtp, tosend, ses, mailgun,
 *    sendgrid, sendinblue, sparkpost, pepipost, postmark, elasticmail,
 *    smtp2go, gmail, outlook, cloudflare, default (PHP mail).
 *  - table {prefix}fsmpt_email_logs, created on activation: status
 *    pending|sent|failed, created_at, composite index created_at,status.
 * Without a connection the plugin hands the mail to PHP mail().
 */
final class FluentSmtpReader extends AbstractMailReader
{
    public function slug(): string
    {
        return 'fluent-smtp';
    }

    protected function pluginFile(): string
    {
        return 'fluent-smtp/fluent-smtp.php';
    }

    protected function versionConstant(): string
    {
        return 'FLUENTMAIL_PLUGIN_VERSION';
    }

    protected function minVersion(): string
    {
        return '2.0';
    }

    protected function maxVersionExclusive(): string
    {
        return '3.0';
    }

    public function read(): array
    {
        $settings = $this->optionArray('fluentmail-settings');
        $connections = is_array($settings['connections'] ?? null) ? $settings['connections'] : [];
        $defaultKey = self::path($settings, ['misc', 'default_connection']);
        $connection = is_string($defaultKey) && isset($connections[$defaultKey]) ? $connections[$defaultKey] : reset($connections);
        $provider = is_array($connection) ? self::path($connection, ['provider_settings', 'provider']) : null;
        $provider = is_string($provider) ? strtolower($provider) : null;

        if ($provider === null || $provider === 'default') {
            $transport = self::TRANSPORT_PHP_MAIL;
        } else {
            $transport = $provider === 'smtp' ? self::TRANSPORT_SMTP : self::TRANSPORT_API;
        }

        $logging = (string) (self::path($settings, ['misc', 'log_emails']) ?? 'yes') === 'yes';
        $errors = null;
        $sent = null;

        if ($logging) {
            $table = $this->table('fsmpt_email_logs');

            if ($this->tableExists($table)) {
                $errors = $this->countWithin($table, "status = 'failed'");
                $sent = $this->countWithin($table, "status = 'sent'");
            }
        }

        return [
            'transport' => $transport,
            'provider_configured' => $provider !== null && $provider !== 'default',
            'errors_7d' => $errors,
            'sent_7d' => $sent,
            'logging_enabled' => $logging,
        ];
    }
}
