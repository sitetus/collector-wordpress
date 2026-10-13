<?php

namespace Sitetus\WP;

use Sitetus\WP\Readers\Mail\AbstractMailReader;
use Sitetus\WP\Readers\Mail\FluentSmtpReader;
use Sitetus\WP\Readers\Mail\MailReader;
use Sitetus\WP\Readers\Mail\WpMailSmtpReader;
use Sitetus\WP\Readers\ReadBudget;

/**
 * The opt-in `mail_delivery` module (0.4.0): how the site's e-mail leaves
 * the server — through the server's own mail() (no delivery plugin, or a
 * plugin still on its default), an SMTP host or a provider API — and what
 * the delivery plugin's own log says about the last seven days. Transport
 * class, booleans and counts only; never an address, host, key or subject.
 *
 * Without a recognised plugin WordPress' wp_mail() hands the message to
 * PHP mail(), so the transport is php_mail with `plugin: null`. A known
 * delivery plugin this module cannot read yet is reported as unsupported
 * (transport null) rather than guessed.
 */
final class MailDeliveryModule
{
    public const ID = 'mail_delivery';

    public const BUDGET_SECONDS = 2.0;

    /**
     * Reader classes in preference order.
     *
     * @var array<int, class-string<MailReader>>
     */
    public const READERS = [WpMailSmtpReader::class, FluentSmtpReader::class];

    /**
     * Delivery plugins without a reader yet (wordpress.org slugs, verified
     * 03.10.2026): when one is active the transport is not guessed.
     *
     * @var array<int, string>
     */
    public const OTHER_MAIL_PLUGINS = [
        'post-smtp',
        'easy-wp-smtp',
        'wp-smtp',
        'smtp-mailer',
        'gmail-smtp',
        'mailgun',
        'wp-ses',
        'sendgrid-email-delivery-simplified',
        'postmark-approved-wordpress-plugin',
    ];

    /**
     * @param array<int, MailReader>|null $readers stand-ins in tests; null builds the real ones
     * @return array{plugin: array{slug: string, version: string, supported: bool}|null, transport: string|null, provider_configured: bool|null, errors_7d: int|null, sent_7d: int|null, logging_enabled: bool|null}
     */
    public static function run(?array $readers = null, ?ReadBudget $budget = null): array
    {
        $budget = $budget ?? new ReadBudget(self::BUDGET_SECONDS);
        $readers = $readers ?? self::readers($budget);
        $empty = ['transport' => null, 'provider_configured' => null, 'errors_7d' => null, 'sent_7d' => null, 'logging_enabled' => null];

        foreach ($readers as $reader) {
            if (! $reader->isActive()) {
                continue;
            }

            $version = $reader->version();
            $supported = $version !== null && $reader->supports($version);
            $plugin = ['slug' => $reader->slug(), 'version' => $version ?? '0', 'supported' => $supported];

            return ['plugin' => $plugin] + ($supported ? $reader->read() : $empty);
        }

        $other = self::activeOtherMailPlugin();

        if ($other !== null) {
            return ['plugin' => $other + ['supported' => false]] + $empty;
        }

        if (self::wpMailOverridden()) {
            return ['plugin' => null] + $empty;
        }

        return ['plugin' => null, 'transport' => AbstractMailReader::TRANSPORT_PHP_MAIL, 'provider_configured' => false] + $empty;
    }

    /**
     * @return array<int, MailReader>
     */
    public static function readers(ReadBudget $budget): array
    {
        return array_map(static function (string $class) use ($budget): MailReader {
            return new $class($budget);
        }, self::READERS);
    }

    /**
     * The first active delivery plugin from OTHER_MAIL_PLUGINS, with its
     * header version.
     *
     * @return array{slug: string, version: string}|null
     */
    public static function activeOtherMailPlugin(): ?array
    {
        if (! function_exists('get_plugins') || ! function_exists('is_plugin_active')) {
            return null;
        }

        foreach ((array) get_plugins() as $file => $header) {
            $slug = strtolower(strpos($file, '/') !== false ? (string) strstr($file, '/', true) : (string) preg_replace('/\.php$/', '', $file));

            if (in_array($slug, self::OTHER_MAIL_PLUGINS, true) && is_plugin_active($file)) {
                $version = is_array($header) && is_scalar($header['Version'] ?? null) ? substr(trim((string) $header['Version']), 0, 32) : '';

                return ['slug' => $slug, 'version' => $version !== '' ? $version : '0'];
            }
        }

        return null;
    }

    /**
     * wp_mail() is pluggable: a theme, mu-plugin or host may replace it,
     * in which case the transport cannot be told from here.
     */
    private static function wpMailOverridden(): bool
    {
        if (! function_exists('wp_mail')) {
            return false;
        }

        try {
            $file = (new \ReflectionFunction('wp_mail'))->getFileName();
        } catch (\ReflectionException $exception) {
            return false;
        }

        return is_string($file) && substr(str_replace('\\', '/', $file), -24) !== 'wp-includes/pluggable.php';
    }
}
