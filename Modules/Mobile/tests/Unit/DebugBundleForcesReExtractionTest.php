<?php

declare(strict_types=1);

// The suite runs from both composer roots: the desktop one, where the mobile
// config sits under mobile-app/, and the mobile-app one, where it is the app's own
// config. Resolve whichever exists rather than assuming.
function debugBundleConfigPath(): string
{
    $nested = base_path('mobile-app/config/nativephp.php');

    return is_file($nested) ? $nested : base_path('config/nativephp.php');
}

function debugBundleConfigVersion(mixed $flag): string
{
    $previous = getenv('NATIVEPHP_DEBUG_BUNDLE');

    if ($flag === null) {
        putenv('NATIVEPHP_DEBUG_BUNDLE');
        unset($_ENV['NATIVEPHP_DEBUG_BUNDLE'], $_SERVER['NATIVEPHP_DEBUG_BUNDLE']);
    } else {
        putenv('NATIVEPHP_DEBUG_BUNDLE='.$flag);
        $_ENV['NATIVEPHP_DEBUG_BUNDLE'] = $flag;
        $_SERVER['NATIVEPHP_DEBUG_BUNDLE'] = $flag;
    }

    putenv('NATIVEPHP_APP_VERSION=9.9.9');
    $_ENV['NATIVEPHP_APP_VERSION'] = '9.9.9';
    $_SERVER['NATIVEPHP_APP_VERSION'] = '9.9.9';

    $config = require debugBundleConfigPath();

    putenv('NATIVEPHP_APP_VERSION');
    unset($_ENV['NATIVEPHP_APP_VERSION'], $_SERVER['NATIVEPHP_APP_VERSION']);
    if ($previous === false) {
        putenv('NATIVEPHP_DEBUG_BUNDLE');
        unset($_ENV['NATIVEPHP_DEBUG_BUNDLE'], $_SERVER['NATIVEPHP_DEBUG_BUNDLE']);
    }

    return (string) $config['version'];
}

// native:run installs a new binary but keeps the previously extracted PHP code,
// which presented as a whole-app 404 that survived restarts and matched no
// middleware. Both shells honour one escape hatch: a bundle id of the literal
// string DEBUG re-extracts every launch, and this wires our config to it.

it('stamps the bundle DEBUG when device development is on', function (): void {
    expect(debugBundleConfigVersion('true'))->toBe('DEBUG');
});

it('ships the real version when the flag is absent', function (): void {
    expect(debugBundleConfigVersion(null))->toBe('9.9.9');
});

it('ships the real version when the flag is off', function (): void {
    expect(debugBundleConfigVersion('false'))->toBe('9.9.9');
});

it('reads every ordinary spelling of off as off', function (): void {
    // env() only types the literal true/false/null spellings; everything else
    // arrives as a non-empty string, and a truthiness test shipped a release
    // build stamped DEBUG for each of these.
    foreach (['off', 'no', 'OFF', 'No', '0', ''] as $spelling) {
        expect(debugBundleConfigVersion($spelling))->toBe('9.9.9', "flag '{$spelling}' enabled the debug bundle");
    }
});

it('reads every ordinary spelling of on as on', function (): void {
    foreach (['true', 'TRUE', '1', 'yes', 'on'] as $spelling) {
        expect(debugBundleConfigVersion($spelling))->toBe('DEBUG', "flag '{$spelling}' did not enable the debug bundle");
    }
});

it('never enables the debug bundle in a release workflow', function (): void {
    // A release that re-extracted on every launch would be slow to start for
    // no reason, and would mask a genuinely failed update.
    $workflows = glob(base_path('.github/workflows/*.yml'))
        ?: glob(base_path('../.github/workflows/*.yml'));

    expect($workflows)->not->toBeEmpty('no workflows found to check from either composer root');

    foreach ($workflows as $workflow) {
        $body = (string) file_get_contents($workflow);

        expect(str_contains($body, 'NATIVEPHP_DEBUG_BUNDLE'))->toBeFalse(
            basename($workflow).' sets NATIVEPHP_DEBUG_BUNDLE'
        );
    }
});
