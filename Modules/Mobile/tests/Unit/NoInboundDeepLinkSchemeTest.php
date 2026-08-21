<?php

declare(strict_types=1);

// `beatrax://pair` resolves to no activity on a phone, so the QR payload is
// consumable only by the in-app camera. That is the decision, not an omission:
// registering the scheme generates a BROWSABLE, host-unrestricted intent
// filter, and the payload names a relay endpoint the device adopts before the
// safety-number gate ever runs. A camera has to be pointed; a link does not.

// The suite runs from both composer roots: the desktop one, where the mobile
// config sits under mobile-app/, and the mobile-app one, where it is the app's
// own config. Resolve whichever exists rather than assuming.
function deepLinkConfigPath(): string
{
    $nested = base_path('mobile-app/config/nativephp.php');

    return is_file($nested) ? $nested : base_path('config/nativephp.php');
}

/** @return array<string, mixed> */
function deepLinkConfigWithSchemeInEnv(string $scheme): array
{
    putenv('NATIVEPHP_DEEPLINK_SCHEME='.$scheme);
    putenv('NATIVEPHP_DEEPLINK_HOST=beatrax.app');
    $_ENV['NATIVEPHP_DEEPLINK_SCHEME'] = $scheme;
    $_SERVER['NATIVEPHP_DEEPLINK_SCHEME'] = $scheme;
    $_ENV['NATIVEPHP_DEEPLINK_HOST'] = 'beatrax.app';
    $_SERVER['NATIVEPHP_DEEPLINK_HOST'] = 'beatrax.app';

    try {
        /** @var array<string, mixed> $config */
        $config = require deepLinkConfigPath();

        return $config;
    } finally {
        putenv('NATIVEPHP_DEEPLINK_SCHEME');
        putenv('NATIVEPHP_DEEPLINK_HOST');
        unset(
            $_ENV['NATIVEPHP_DEEPLINK_SCHEME'],
            $_SERVER['NATIVEPHP_DEEPLINK_SCHEME'],
            $_ENV['NATIVEPHP_DEEPLINK_HOST'],
            $_SERVER['NATIVEPHP_DEEPLINK_HOST'],
        );
    }
}

it('claims no inbound URL scheme even when the environment names one', function (): void {
    $config = deepLinkConfigWithSchemeInEnv('beatrax');

    expect($config['deeplink_scheme'])->toBeNull();
});

it('claims no verified https host either', function (): void {
    $config = deepLinkConfigWithSchemeInEnv('beatrax');

    expect($config['deeplink_host'] ?? null)->toBeNull();
});

// The two keys above are inert only while the packager still reads them as its
// opt-in. An upgrade that stops consulting either one registers the filter
// again, which is exactly the state this decision exists to keep out.
it('pins the packager gate the decision relies on', function (): void {
    $candidates = [
        base_path('mobile-app/vendor/nativephp/mobile/src/Concerns/RunsAndroid.php'),
        base_path('vendor/nativephp/mobile/src/Concerns/RunsAndroid.php'),
    ];

    $source = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $source = (string) file_get_contents($candidate);
            break;
        }
    }

    if ($source === null) {
        test()->markTestSkipped('nativephp/mobile installs only under the mobile-app composer root');
    }

    expect($source)->toContain("config('nativephp.deeplink_scheme')")
        ->and($source)->toContain("config('nativephp.deeplink_host')")
        ->and($source)->toContain('if (! $scheme && ! $host) {');
});
