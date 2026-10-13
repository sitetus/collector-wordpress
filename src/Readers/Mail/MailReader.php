<?php

namespace Sitetus\WP\Readers\Mail;

/**
 * One third-party mail delivery plugin as the mail_delivery module sees
 * it. A reader answers which plugin, is it active, do I know this
 * version's storage, and what its records say — transport class, whether
 * a provider is configured, and bounded counts from its own log. Never
 * an address, a subject, a host name or a credential. Adding the next
 * plugin means adding one class that implements this and listing it in
 * MailDeliveryModule::READERS.
 */
interface MailReader
{
    /**
     * The wordpress.org directory slug (also the inventory name).
     */
    public function slug(): string;

    public function isActive(): bool;

    /**
     * The installed version, or null when it cannot be told.
     */
    public function version(): ?string;

    /**
     * Whether this reader knows the storage layout of the given version.
     */
    public function supports(string $version): bool;

    /**
     * Reads the plugin's settings and log. `transport` is php_mail (the
     * server's own mail()), smtp or api; counts are null when the plugin
     * keeps no such log, logging is off, or the budget is spent.
     *
     * @return array{transport: string|null, provider_configured: bool|null, errors_7d: int|null, sent_7d: int|null, logging_enabled: bool|null}
     */
    public function read(): array;
}
