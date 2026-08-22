<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

// Xcode signs a development build with the DEVELOPMENT_TEAM that NativePHP
// writes into the generated project, from `nativephp.development_team` first
// and from `security find-identity` second. The fallback is not a failure
// mode, which is the problem: it silently signs under a guess.

// That guess takes the FIRST identity the keychain lists, and an "Apple
// Development" identity's parenthesised value is a member id rather than a
// team id. Whether the build signs then depends on keychain ordering.
final class IosSigningPreflight
{
    public const string TEAM_ID_ENV_KEY = 'IOS_TEAM_ID';

    public static function teamIdWarning(?string $configuredTeam): ?string
    {
        if ($configuredTeam !== null && trim($configuredTeam) !== '') {
            return null;
        }

        return self::TEAM_ID_ENV_KEY.' is unset, so this build signs under whichever team '
            .'`security find-identity` happens to list first. An Apple Development identity '
            .'reports a member id there, not a team id, and Xcode cannot sign with one. '
            .'Set '.self::TEAM_ID_ENV_KEY.' in mobile-app/.env.';
    }
}
