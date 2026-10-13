<?php

namespace Sitetus\WP\Readers\Security;

use Sitetus\WP\Readers\ReadsPluginStorage;

/**
 * Shared ground of the security readers: the generic plugin-storage
 * helpers (ReadsPluginStorage), the bounded administrator id list, a
 * usermeta count over those administrators, and the empty read every
 * reader starts from. The administrator list is bounded like the core
 * slice's (CoreChecks::MAX_ADMINS); an enrolment count is always a
 * prepared COUNT over user ids — no row, no name, no secret.
 */
abstract class AbstractSecurityReader implements SecurityPluginReader
{
    use ReadsPluginStorage;

    public const MAX_ADMINS = 200;

    public const MODE_ENABLED = 'enabled';

    public const MODE_LEARNING = 'learning';

    public const MODE_DISABLED = 'disabled';

    /**
     * @return array{scan: null, firewall: null, two_factor: null, hardening: null, login_pressure: null, roles: array{firewall: bool, login_limiter: bool}}
     */
    protected static function emptyRead(): array
    {
        return [
            'scan' => null,
            'firewall' => null,
            'two_factor' => null,
            'hardening' => null,
            'login_pressure' => null,
            'roles' => ['firewall' => false, 'login_limiter' => false],
        ];
    }

    /**
     * @return array{xmlrpc_disabled: bool|null, user_enumeration_blocked: bool|null, version_hidden: bool|null, file_editing_disabled: bool|null, login_url_changed: bool|null, uploads_php_blocked: bool|null}
     */
    protected static function hardening(?bool $xmlrpc, ?bool $enumeration, ?bool $version, ?bool $fileEditing, ?bool $loginUrl, ?bool $uploadsPhp): array
    {
        return [
            'xmlrpc_disabled' => $xmlrpc,
            'user_enumeration_blocked' => $enumeration,
            'version_hidden' => $version,
            'file_editing_disabled' => $fileEditing,
            'login_url_changed' => $loginUrl,
            'uploads_php_blocked' => $uploadsPhp,
        ];
    }

    /**
     * @return array<int, int>
     */
    protected function adminIds(): array
    {
        if (! function_exists('get_users')) {
            return [];
        }

        $ids = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => self::MAX_ADMINS]);

        return array_values(array_map('intval', array_filter((array) $ids, 'is_numeric')));
    }

    /**
     * How many of the given users carry a usermeta row with this key whose
     * value is not one of the "empty" spellings; null when not measurable.
     *
     * @param array<int, int> $userIds
     * @param array<int, string> $emptyValues values that mean "not enrolled" (always includes '')
     */
    protected function usersWithMeta(array $userIds, string $metaKey, array $emptyValues = []): ?int
    {
        if ($userIds === []) {
            return 0;
        }

        $empty = array_values(array_unique(array_merge([''], $emptyValues)));
        $params = array_merge([$metaKey], $empty, $userIds);

        return $this->count(
            "SELECT COUNT(DISTINCT user_id) FROM {$this->table('usermeta')} WHERE meta_key = %s AND meta_value NOT IN (".self::placeholders($empty).') AND user_id IN ('.implode(', ', array_fill(0, count($userIds), '%d')).')',
            $params
        );
    }

    /**
     * Name → value pairs from a prepared, read-only two-column query
     * (`SELECT name, val …`); null when the budget is spent or the query
     * fails. Values are kept as bounded strings.
     *
     * @param array<int, string|int|float> $params
     * @return array<string, string>|null
     */
    protected function pairs(string $sql, array $params = []): ?array
    {
        if (! $this->budget->open()) {
            return null;
        }

        $wpdb = $this->wpdb();
        $prepared = $params === [] ? $sql : $wpdb->prepare($sql, ...$params);

        if (! is_string($prepared)) {
            return null;
        }

        $rows = $wpdb->get_results($prepared, 'ARRAY_A');

        if (! is_array($rows)) {
            return null;
        }

        $pairs = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name']) && is_scalar($row['name']) && array_key_exists('val', $row) && (is_scalar($row['val']) || $row['val'] === null)) {
                $pairs[(string) $row['name']] = substr((string) $row['val'], 0, 64);
            }
        }

        return $pairs;
    }

    /**
     * Placeholders for an IN (...) list, one %s per entry.
     *
     * @param array<int, string> $values
     */
    protected static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '%s'));
    }

    /**
     * A checkbox-style option value ('1', 1, true, 'on', 'yes') as a bool;
     * null when the key is absent.
     *
     * @param array<string, mixed> $options
     */
    protected static function flag(array $options, string $key): ?bool
    {
        if (! array_key_exists($key, $options)) {
            return null;
        }

        $value = $options[$key];

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
    }

    /**
     * Hours since a unix timestamp; null for a missing or absurd value.
     *
     * @param mixed $timestamp
     */
    protected static function ageHours($timestamp): ?int
    {
        if (! is_numeric($timestamp) || (float) $timestamp <= 0) {
            return null;
        }

        return max(0, (int) floor((time() - (float) $timestamp) / 3600));
    }
}
