<?php

namespace Sitetus\WP\Readers\Security;

/**
 * Limit Login Attempts Reloaded (limit-login-attempts-reloaded). Storage
 * verified on 3.3.10 (03.10.2026): options with the limit_login_ prefix —
 * retries_stats (an array keyed by the unix timestamp of the hour,
 * value = failed logins in that hour; old buckets are pruned by the
 * plugin), lockouts_total (a running counter; absent before the first
 * lockout), active_app ('local', or 'custom' when the premium cloud app
 * holds the counters and the local ones stay empty). The retries,
 * lockouts and logged options are keyed by IP address: never read.
 */
final class LimitLoginAttemptsReader extends AbstractSecurityReader
{
    public const WINDOW_SECONDS = 86400;

    public function slug(): string
    {
        return 'limit-login-attempts-reloaded';
    }

    protected function pluginFile(): string
    {
        return 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php';
    }

    protected function versionConstant(): string
    {
        return '';
    }

    protected function minVersion(): string
    {
        return '2.20';
    }

    protected function maxVersionExclusive(): string
    {
        return '4.0';
    }

    public function read(): array
    {
        $read = self::emptyRead();
        $read['roles'] = ['firewall' => false, 'login_limiter' => true];

        if ($this->option('limit_login_active_app', 'local') === 'custom') {
            $read['login_pressure'] = ['failed_logins_24h' => null, 'lockouts_total' => null];

            return $read;
        }

        $stats = $this->optionArray('limit_login_retries_stats');
        $since = time() - self::WINDOW_SECONDS;
        $failed = 0;

        foreach ($stats as $hour => $count) {
            if (is_numeric($hour) && (int) $hour >= $since && is_numeric($count)) {
                $failed += max(0, (int) $count);
            }
        }

        $lockouts = $this->option('limit_login_lockouts_total', 0);
        $read['login_pressure'] = [
            'failed_logins_24h' => $failed,
            'lockouts_total' => is_numeric($lockouts) ? max(0, (int) $lockouts) : 0,
        ];

        return $read;
    }
}
