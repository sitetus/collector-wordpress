<?php

namespace Sitetus\WP\Readers\Security;

/**
 * One third-party security plugin as the security_plugin module sees it.
 * The SEO contract (PluginReader) takes the public post types because
 * every SEO fact is per post type; a security plugin's facts are site
 * wide, so this contract reads without arguments — the same four
 * identity questions, then one read in a plugin-independent shape. A
 * reader answers with counts, booleans, mode names and the plugin's own
 * slug only: never an IP address, a user name, a file path, a finding
 * text or a key. Every block a plugin does not keep is null, never a
 * guess. Adding the next plugin means adding one class that implements
 * this and listing it in SecurityPluginModule::READERS (and its slugs in
 * FAMILIES).
 */
interface SecurityPluginReader
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
     * An unknown version yields `unsupported: version` rather than a guess.
     */
    public function supports(string $version): bool;

    /**
     * Reads the plugin's own records. `roles` says what this plugin is
     * doing right now (a firewall, a login limiter) for the conflict count.
     *
     * @return array{
     *   scan: array{open_critical: int|null, open_total: int|null, last_scan_age_hours: int|null, last_scan_failed: bool|null}|null,
     *   firewall: array{mode: string|null, extended_protection: bool|null}|null,
     *   two_factor: array{admins_total: int|null, admins_enrolled: int|null, enforced_for_admins: bool|null}|null,
     *   hardening: array{xmlrpc_disabled: bool|null, user_enumeration_blocked: bool|null, version_hidden: bool|null, file_editing_disabled: bool|null, login_url_changed: bool|null, uploads_php_blocked: bool|null}|null,
     *   login_pressure: array{failed_logins_24h: int|null, lockouts_total: int|null}|null,
     *   roles: array{firewall: bool, login_limiter: bool}
     * }
     */
    public function read(): array;
}
