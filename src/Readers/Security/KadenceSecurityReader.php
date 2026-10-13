<?php

namespace Sitetus\WP\Readers\Security;

/**
 * Kadence Security, formerly Solid / iThemes Security (better-wp-security).
 * Storage verified on 10.0.5 (03.10.2026):
 *  - active modules: ITSEC_Modules::get_active_modules() (defaults from
 *    each module.json merged with the itsec_active_modules option, which
 *    only exists once a module was toggled — the option alone is the
 *    fallback when the class is not loaded). Fresh install: ban-users,
 *    firewall, brute-force, network-brute-force, backup.
 *  - settings: ITSEC_Modules::get_setting(module, key), stored in the
 *    itsec-storage option as [module][key]. Read: file-change.last_scan
 *    (the log id of the last scan that found changes, 0 otherwise — not
 *    a timestamp), wordpress-tweaks.disable_xmlrpc (enable /
 *    disable_pingbacks / disable), .file_editor, .rest_api
 *    (default-access / restricted-access), .disable_unused_author_pages,
 *    hide-backend.enabled, system-tweaks.uploads_php,
 *    two-factor.exclude_group (10.x enforces 2FA on-boarding for every
 *    login unless the user's group is excluded; older protect_user_type
 *    was migrated into it).
 *  - 2FA enrolment: usermeta _two_factor_enabled_providers (the same key
 *    the Two Factor plugin uses).
 *  - site-scanner results live in itsec_logs (with IPs and user ids) and
 *    itsec_vulnerabilities duplicates the panel's own feed: neither is read;
 *    itsec_lockouts carries hosts and user names: not read.
 */
final class KadenceSecurityReader extends AbstractSecurityReader
{
    public const OPTION_ACTIVE_MODULES = 'itsec_active_modules';

    public const OPTION_STORAGE = 'itsec-storage';

    public function slug(): string
    {
        return 'better-wp-security';
    }

    protected function pluginFile(): string
    {
        return 'better-wp-security/better-wp-security.php';
    }

    protected function versionConstant(): string
    {
        return '';
    }

    protected function minVersion(): string
    {
        return '8.0';
    }

    protected function maxVersionExclusive(): string
    {
        return '11.0';
    }

    public function read(): array
    {
        $read = self::emptyRead();
        $active = $this->activeModules();
        $firewall = in_array('firewall', $active, true);

        if (in_array('file-change', $active, true)) {
            $lastScan = $this->setting('file-change', 'last_scan');
            $read['scan'] = [
                'open_critical' => null,
                'open_total' => is_numeric($lastScan) ? ((int) $lastScan > 0 ? 1 : 0) : null,
                'last_scan_age_hours' => null,
                'last_scan_failed' => null,
            ];
        }

        $read['firewall'] = ['mode' => $firewall ? self::MODE_ENABLED : self::MODE_DISABLED, 'extended_protection' => null];

        if (in_array('two-factor', $active, true)) {
            $admins = $this->adminIds();
            $excluded = $this->setting('two-factor', 'exclude_group');
            $read['two_factor'] = [
                'admins_total' => count($admins),
                'admins_enrolled' => $this->usersWithMeta($admins, '_two_factor_enabled_providers', ['a:0:{}']),
                'enforced_for_admins' => $excluded === null || $excluded === [] || $excluded === '' ? true : null,
            ];
        }

        $tweaks = in_array('wordpress-tweaks', $active, true);
        $read['hardening'] = self::hardening(
            $tweaks ? $this->setting('wordpress-tweaks', 'disable_xmlrpc') === 'disable' : false,
            $tweaks ? ((bool) $this->setting('wordpress-tweaks', 'disable_unused_author_pages') || $this->setting('wordpress-tweaks', 'rest_api') === 'restricted-access') : false,
            null,
            $tweaks ? (bool) $this->setting('wordpress-tweaks', 'file_editor') : false,
            in_array('hide-backend', $active, true) ? (bool) $this->setting('hide-backend', 'enabled') : false,
            in_array('system-tweaks', $active, true) ? (bool) $this->setting('system-tweaks', 'uploads_php') : false
        );
        $read['roles'] = ['firewall' => $firewall, 'login_limiter' => in_array('brute-force', $active, true)];

        return $read;
    }

    /**
     * @return array<int, string>
     */
    private function activeModules(): array
    {
        if (class_exists('ITSEC_Modules') && method_exists('ITSEC_Modules', 'get_active_modules')) {
            $modules = \ITSEC_Modules::get_active_modules();

            return array_values(array_filter(is_array($modules) ? $modules : [], 'is_string'));
        }

        $modules = [];

        foreach ($this->optionArray(self::OPTION_ACTIVE_MODULES) as $module => $enabled) {
            if (is_string($module) && $enabled) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    /**
     * @return mixed
     */
    private function setting(string $module, string $key)
    {
        if (class_exists('ITSEC_Modules') && method_exists('ITSEC_Modules', 'get_setting')) {
            return \ITSEC_Modules::get_setting($module, $key);
        }

        return self::path($this->optionArray(self::OPTION_STORAGE), [$module, $key]);
    }
}
