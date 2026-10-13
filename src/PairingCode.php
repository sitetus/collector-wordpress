<?php

namespace Sitetus\WP;

/**
 * Decodes and validates the pairing code the panel shows on the site's
 * Toplayıcı page: base64url JSON with the install's public identity. The
 * plugin never trusts a field it did not expect and never stores anything
 * it did not validate.
 */
final class PairingCode
{
    public const VERSION = 1;

    /**
     * @return array<string, mixed>|null null when the code is not a valid pairing code
     */
    public static function decode(string $code): ?array
    {
        $code = trim($code);

        if ($code === '' || strlen($code) > 8192 || preg_match('/^[A-Za-z0-9_-]+$/', $code) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($code, '-_', '+/').str_repeat('=', (4 - strlen($code) % 4) % 4), true);

        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);

        if (! is_array($payload) || ($payload['v'] ?? null) !== self::VERSION) {
            return null;
        }

        $projectKey = $payload['project_key'] ?? null;
        $publicKey = $payload['public_key'] ?? null;
        $algo = $payload['algo'] ?? null;
        $nonceFile = $payload['nonce_file'] ?? null;

        if (! is_string($projectKey) || preg_match('/^[a-z0-9]{8,64}$/', $projectKey) !== 1) {
            return null;
        }

        if (! is_string($publicKey) || $publicKey === '' || strlen($publicKey) > 4096) {
            return null;
        }

        if (! in_array($algo, ['ed25519', 'rsa-sha256'], true)) {
            return null;
        }

        if (! is_string($nonceFile) || preg_match('/^\.sitetus-[a-z0-9]{6,32}\.json$/', $nonceFile) !== 1) {
            return null;
        }

        $modules = array_values(array_filter(
            is_array($payload['modules'] ?? null) ? $payload['modules'] : [],
            static function ($module): bool {
                return is_string($module) && in_array($module, Settings::MODULES, true);
            }
        ));

        $expiresAt = $payload['expires_at'] ?? null;

        return [
            'project_key' => $projectKey,
            'public_key' => $publicKey,
            'algo' => $algo,
            'expires_at' => is_numeric($expiresAt) ? (int) $expiresAt : null,
            'nonce_file' => $nonceFile,
            'modules' => $modules,
            'panel' => is_string($payload['panel'] ?? null) ? self::safeUrl($payload['panel']) : '',
            'site' => is_string($payload['site'] ?? null) ? substr($payload['site'], 0, 253) : '',
        ];
    }

    private static function safeUrl(string $url): string
    {
        $url = substr(trim($url), 0, 512);

        // Only used for a link back to the panel in WP admin; plain http stays possible for
        // a development panel, anything else is dropped.
        return preg_match('#^https?://[a-z0-9.-]+(:[0-9]+)?(/[^\s]*)?$#i', $url) === 1 ? $url : '';
    }
}
