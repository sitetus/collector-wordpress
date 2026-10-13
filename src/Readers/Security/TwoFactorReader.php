<?php

namespace Sitetus\WP\Readers\Security;

/**
 * Two Factor (two-factor, the WordPress community plugin). Storage
 * verified on 0.17.0 (03.10.2026): usermeta _two_factor_enabled_providers
 * (a serialized list of provider class names; empty or absent = not
 * enrolled) and _two_factor_provider (the primary one, may stay empty
 * while a provider is enabled — Two_Factor_Core::is_user_using_two_factor
 * looks at the enabled list). The plugin has no enforcement setting of
 * its own (only the two_factor_is_required_for_user filter), so
 * enforced_for_admins is reported as false. TOTP keys and backup codes
 * live in other meta keys that are never touched.
 */
final class TwoFactorReader extends AbstractSecurityReader
{
    public const META_ENABLED_PROVIDERS = '_two_factor_enabled_providers';

    public function slug(): string
    {
        return 'two-factor';
    }

    protected function pluginFile(): string
    {
        return 'two-factor/two-factor.php';
    }

    protected function versionConstant(): string
    {
        return 'TWO_FACTOR_VERSION';
    }

    protected function minVersion(): string
    {
        return '0.5';
    }

    protected function maxVersionExclusive(): string
    {
        return '1.0';
    }

    public function read(): array
    {
        $read = self::emptyRead();
        $admins = $this->adminIds();
        $read['two_factor'] = [
            'admins_total' => count($admins),
            'admins_enrolled' => $this->usersWithMeta($admins, self::META_ENABLED_PROVIDERS, ['a:0:{}']),
            'enforced_for_admins' => false,
        ];

        return $read;
    }
}
