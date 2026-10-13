<?php

namespace Sitetus\WP\Readers\Security;

/**
 * Wordfence (wordfence). Storage verified on 9.0.2 (03.10.2026):
 *  - {prefix}wfconfig (lower-cased table name since the 7.5 case
 *    migration; the CamelCase name is tried second): name/val rows —
 *    scanTime (microtime of the last finished or failed scan),
 *    lastScanCompleted ('ok' or the failure message; absent before the
 *    first scan), lastScanFailureType, waf_status (synced copy of the
 *    WAF storage: enabled / learning-mode / disabled), firewallEnabled,
 *    loginSecurityEnabled, loginSec_requireAdminTwoFactor (legacy),
 *    loginSec_disableAuthorScan, disableCodeExecutionUploads.
 *  - {prefix}wfissues: status ('new' = open), severity (25/50/75/100),
 *    type. Types the panel already judges itself (vulnerable, abandoned,
 *    removed or outdated plugins and themes) are left out of the count.
 *  - {prefix}wfls_settings: require-2fa.administrator ('1' = enforced),
 *    xmlrpc-enabled; {prefix}wfls_2fa_secrets: one row per enrolled user.
 *  - wfWAF::getInstance()->getStorageEngine()->getConfig('wafStatus') is
 *    the live WAF mode when the class is loaded; WFWAF_AUTO_PREPEND says
 *    whether the firewall runs before WordPress (extended protection).
 * Nothing from wflogins, wfhits, wfblocks7 or the issue messages leaves.
 */
final class WordfenceReader extends AbstractSecurityReader
{
    public const SEVERITY_HIGH = 75;

    /**
     * @var array<int, string>
     */
    public const OVERLAPPING_ISSUE_TYPES = ['wfPluginVulnerable', 'wfPluginAbandoned', 'wfPluginRemoved', 'wfPluginUpgrade', 'wfThemeUpgrade', 'wfThemeVulnerable', 'wfThemeAbandoned', 'wfThemeRemoved', 'wfUpgrade', 'wfUpgradeError'];

    /**
     * @var array<int, string>
     */
    public const CONFIG_KEYS = ['scanTime', 'lastScanCompleted', 'lastScanFailureType', 'waf_status', 'firewallEnabled', 'loginSecurityEnabled', 'loginSec_requireAdminTwoFactor', 'loginSec_disableAuthorScan', 'disableCodeExecutionUploads'];

    public function slug(): string
    {
        return 'wordfence';
    }

    protected function pluginFile(): string
    {
        return 'wordfence/wordfence.php';
    }

    protected function versionConstant(): string
    {
        return 'WORDFENCE_VERSION';
    }

    protected function minVersion(): string
    {
        return '7.5';
    }

    protected function maxVersionExclusive(): string
    {
        return '10.0';
    }

    public function read(): array
    {
        $read = self::emptyRead();
        $configTable = $this->firstTable(['wfconfig', 'wfConfig']);
        $config = $configTable === null ? [] : ($this->pairs("SELECT name, val FROM {$configTable} WHERE name IN (".self::placeholders(self::CONFIG_KEYS).')', self::CONFIG_KEYS) ?? []);

        $read['scan'] = $this->scan($config);
        $read['firewall'] = $this->firewall($config);
        $read['two_factor'] = $this->twoFactor($config);
        $read['hardening'] = self::hardening(
            $this->xmlrpcDisabled(),
            self::flag($config, 'loginSec_disableAuthorScan'),
            null,
            null,
            null,
            self::flag($config, 'disableCodeExecutionUploads')
        );
        $read['roles'] = [
            'firewall' => in_array($read['firewall']['mode'], [self::MODE_ENABLED, self::MODE_LEARNING], true),
            'login_limiter' => self::flag($config, 'loginSecurityEnabled') === true || self::flag($config, 'firewallEnabled') === true,
        ];

        return $read;
    }

    /**
     * @param array<string, string> $config
     * @return array{open_critical: int|null, open_total: int|null, last_scan_age_hours: int|null, last_scan_failed: bool|null}
     */
    private function scan(array $config): array
    {
        $issues = $this->firstTable(['wfissues', 'wfIssues']);
        $critical = null;
        $total = null;

        if ($issues !== null) {
            $excluded = self::OVERLAPPING_ISSUE_TYPES;
            $critical = $this->count("SELECT COUNT(*) FROM {$issues} WHERE status = 'new' AND severity >= %d AND type NOT IN (".self::placeholders($excluded).')', array_merge([self::SEVERITY_HIGH], $excluded));
            $total = $this->count("SELECT COUNT(*) FROM {$issues} WHERE status = 'new' AND type NOT IN (".self::placeholders($excluded).')', $excluded);
        }

        $completed = $config['lastScanCompleted'] ?? null;

        return [
            'open_critical' => $critical,
            'open_total' => $total,
            'last_scan_age_hours' => self::ageHours($config['scanTime'] ?? null),
            'last_scan_failed' => $completed === null || $completed === '' ? null : $completed !== 'ok',
        ];
    }

    /**
     * @param array<string, string> $config
     * @return array{mode: string|null, extended_protection: bool|null}
     */
    private function firewall(array $config): array
    {
        $status = $this->liveWafStatus() ?? ($config['waf_status'] ?? null);

        return [
            'mode' => self::mode($status),
            'extended_protection' => defined('WFWAF_AUTO_PREPEND') ? (bool) constant('WFWAF_AUTO_PREPEND') : null,
        ];
    }

    /**
     * @param array<string, string> $config
     * @return array{admins_total: int|null, admins_enrolled: int|null, enforced_for_admins: bool|null}
     */
    private function twoFactor(array $config): array
    {
        $admins = $this->adminIds();
        $secrets = $this->firstTable(['wfls_2fa_secrets']);
        $enrolled = null;

        if ($secrets !== null) {
            $enrolled = $admins === []
                ? 0
                : $this->count("SELECT COUNT(DISTINCT user_id) FROM {$secrets} WHERE user_id IN (".implode(', ', array_fill(0, count($admins), '%d')).')', $admins);
        }

        $settingsTable = $this->firstTable(['wfls_settings']);
        $settings = $settingsTable === null ? [] : ($this->pairs("SELECT name, value AS val FROM {$settingsTable} WHERE name IN (%s, %s)", ['require-2fa.administrator', 'xmlrpc-enabled']) ?? []);
        $required = self::flag($settings, 'require-2fa.administrator');
        $legacy = self::flag($config, 'loginSec_requireAdminTwoFactor');

        return [
            'admins_total' => count($admins),
            'admins_enrolled' => $enrolled,
            'enforced_for_admins' => $required === null && $legacy === null ? null : ($required === true || $legacy === true),
        ];
    }

    private function xmlrpcDisabled(): ?bool
    {
        $settingsTable = $this->firstTable(['wfls_settings']);

        if ($settingsTable === null) {
            return null;
        }

        $settings = $this->pairs("SELECT name, value AS val FROM {$settingsTable} WHERE name = %s", ['xmlrpc-enabled']) ?? [];
        $enabled = self::flag($settings, 'xmlrpc-enabled');

        return $enabled === null ? null : ! $enabled;
    }

    private function liveWafStatus(): ?string
    {
        if (! class_exists('wfWAF')) {
            return null;
        }

        try {
            $waf = \wfWAF::getInstance();
            $status = is_object($waf) && method_exists($waf, 'getStorageEngine') ? $waf->getStorageEngine()->getConfig('wafStatus') : null;
        } catch (\Throwable $exception) {
            return null;
        }

        return is_string($status) ? $status : null;
    }

    /**
     * @param mixed $status
     */
    private static function mode($status): ?string
    {
        switch (is_string($status) ? strtolower($status) : '') {
            case 'enabled':
                return self::MODE_ENABLED;
            case 'learning-mode':
                return self::MODE_LEARNING;
            case 'disabled':
                return self::MODE_DISABLED;
            default:
                return null;
        }
    }

    /**
     * The first of the given table suffixes that exists (Wordfence renamed
     * its tables to lower case in 7.5; older installs keep CamelCase).
     *
     * @param array<int, string> $suffixes
     */
    private function firstTable(array $suffixes): ?string
    {
        foreach ($suffixes as $suffix) {
            $table = $this->table($suffix);

            if ($this->tableExists($table)) {
                return $table;
            }
        }

        return null;
    }
}
