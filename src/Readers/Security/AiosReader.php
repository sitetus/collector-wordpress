<?php

namespace Sitetus\WP\Readers\Security;

/**
 * All-In-One Security (all-in-one-wp-security-and-firewall). Storage
 * verified on 5.4.10 (03.10.2026): one option, aio_wp_security_configs,
 * whose checkbox keys hold '1' or '' — aiowps_enable_basic_firewall,
 * aiowps_enable_6g_firewall, aiowps_enable_pingback_firewall (blocks all
 * XML-RPC access), aiowps_enable_login_lockdown,
 * aiowps_prevent_users_enumeration, aiowps_remove_wp_generator_meta_info,
 * aiowps_disable_file_editing, aiowps_enable_rename_login_page,
 * aiowps_enable_automated_fcd_scan and the bool
 * aiowps_fcds_change_detected (the file-change scan found something
 * nobody has looked at yet). The bundled two-factor module (Simba TFA)
 * keeps enrolment in usermeta tfa_enable_tfa (1 / 0) and the per-role
 * requirement in the tfa_required_{role} option. The lockdown, failed
 * login and audit tables carry IP addresses and user names: not read.
 */
final class AiosReader extends AbstractSecurityReader
{
    public const OPTION_CONFIGS = 'aio_wp_security_configs';

    public function slug(): string
    {
        return 'all-in-one-wp-security-and-firewall';
    }

    protected function pluginFile(): string
    {
        return 'all-in-one-wp-security-and-firewall/wp-security.php';
    }

    protected function versionConstant(): string
    {
        return '';
    }

    protected function minVersion(): string
    {
        return '5.0';
    }

    protected function maxVersionExclusive(): string
    {
        return '6.0';
    }

    public function read(): array
    {
        $read = self::emptyRead();
        $configs = $this->optionArray(self::OPTION_CONFIGS);

        if ($configs === []) {
            return $read;
        }

        if (self::flag($configs, 'aiowps_enable_automated_fcd_scan') === true) {
            $read['scan'] = [
                'open_critical' => null,
                'open_total' => self::flag($configs, 'aiowps_fcds_change_detected') === true ? 1 : 0,
                'last_scan_age_hours' => null,
                'last_scan_failed' => null,
            ];
        }

        $firewall = self::flag($configs, 'aiowps_enable_basic_firewall') === true || self::flag($configs, 'aiowps_enable_6g_firewall') === true;
        $read['firewall'] = ['mode' => $firewall ? self::MODE_ENABLED : self::MODE_DISABLED, 'extended_protection' => null];

        $admins = $this->adminIds();
        $read['two_factor'] = [
            'admins_total' => count($admins),
            'admins_enrolled' => $this->usersWithMeta($admins, 'tfa_enable_tfa', ['0', 'false']),
            'enforced_for_admins' => (bool) $this->option('tfa_required_administrator', false),
        ];

        $read['hardening'] = self::hardening(
            self::flag($configs, 'aiowps_enable_pingback_firewall') === true,
            self::flag($configs, 'aiowps_prevent_users_enumeration') === true,
            self::flag($configs, 'aiowps_remove_wp_generator_meta_info') === true,
            self::flag($configs, 'aiowps_disable_file_editing') === true,
            self::flag($configs, 'aiowps_enable_rename_login_page') === true,
            null
        );
        $read['roles'] = ['firewall' => $firewall, 'login_limiter' => self::flag($configs, 'aiowps_enable_login_lockdown') === true];

        return $read;
    }
}
