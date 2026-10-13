<?php

namespace Sitetus\WP\Readers\Security;

/**
 * Really Simple Security, formerly Really Simple SSL (really-simple-ssl).
 * Storage verified on 9.8.3 (03.10.2026): one array option, rsssl_options
 * (get_site_option on a network), whose keys hold 1 / 0 or bool and only
 * exist once set — disable_xmlrpc, disable_user_enumeration,
 * hide_wordpress_version, disable_file_editing,
 * block_code_execution_uploads, change_login_url_enabled,
 * enable_limited_login_attempts, enable_firewall (Pro),
 * login_protection_enabled (2FA on), two_fa_forced_roles (role slugs).
 * Per-user enrolment: usermeta rsssl_two_fa_status_totp / _email /
 * _passkey with the value 'active' (other values: 'open', 'disabled').
 * The vulnerability scanner's results are encrypted files and duplicate
 * the panel's own feed; the limited-login tables carry IPs: neither is
 * read. The option also holds captcha secrets and the encryption key —
 * only the keys named above are ever looked at.
 */
final class ReallySimpleSecurityReader extends AbstractSecurityReader
{
    public const OPTION = 'rsssl_options';

    /**
     * @var array<int, string>
     */
    public const STATUS_META_KEYS = ['rsssl_two_fa_status_totp', 'rsssl_two_fa_status_email', 'rsssl_two_fa_status_passkey'];

    public function slug(): string
    {
        return 'really-simple-ssl';
    }

    protected function pluginFile(): string
    {
        return 'really-simple-ssl/rlrsssl-really-simple-ssl.php';
    }

    protected function versionConstant(): string
    {
        return 'rsssl_version';
    }

    protected function minVersion(): string
    {
        return '7.0';
    }

    protected function maxVersionExclusive(): string
    {
        return '10.0';
    }

    public function read(): array
    {
        $read = self::emptyRead();
        $options = $this->options();

        if ($options === []) {
            return $read;
        }

        $firewall = self::flag($options, 'enable_firewall') === true;

        if ($firewall) {
            $read['firewall'] = ['mode' => self::MODE_ENABLED, 'extended_protection' => null];
        }

        if (self::flag($options, 'login_protection_enabled') === true) {
            $admins = $this->adminIds();
            $forced = $options['two_fa_forced_roles'] ?? [];
            $read['two_factor'] = [
                'admins_total' => count($admins),
                'admins_enrolled' => $this->enrolledAdmins($admins),
                'enforced_for_admins' => is_array($forced) && in_array('administrator', $forced, true),
            ];
        }

        $read['hardening'] = self::hardening(
            self::flag($options, 'disable_xmlrpc') === true,
            self::flag($options, 'disable_user_enumeration') === true,
            self::flag($options, 'hide_wordpress_version') === true,
            self::flag($options, 'disable_file_editing') === true,
            self::flag($options, 'change_login_url_enabled') === true,
            self::flag($options, 'block_code_execution_uploads') === true
        );
        $read['roles'] = ['firewall' => $firewall, 'login_limiter' => self::flag($options, 'enable_limited_login_attempts') === true];

        return $read;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        if (function_exists('is_multisite') && is_multisite() && function_exists('get_site_option')) {
            $value = get_site_option(self::OPTION, []);

            return is_array($value) ? $value : [];
        }

        return $this->optionArray(self::OPTION);
    }

    /**
     * @param array<int, int> $admins
     */
    private function enrolledAdmins(array $admins): ?int
    {
        if ($admins === []) {
            return 0;
        }

        $keys = self::STATUS_META_KEYS;

        return $this->count(
            "SELECT COUNT(DISTINCT user_id) FROM {$this->table('usermeta')} WHERE meta_key IN (".self::placeholders($keys).") AND meta_value = 'active' AND user_id IN (".implode(', ', array_fill(0, count($admins), '%d')).')',
            array_merge($keys, $admins)
        );
    }
}
