<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as Config;

/**
 * @link ../../.docs/architecture/module-boundaries.md
 */

// Every one of these reaches a shipped artifact: app_id becomes the macOS
// CFBundleIdentifier, the Windows AppUserModelID and the NSIS registry key;
// description and website become the .deb control fields and the installer
// metadata; author becomes the .deb maintainer. The release build stages its
// environment with `cp .env.example .env` and sets only the version and the
// signing variables, so whatever these default to is what ships.
const DESKTOP_IDENTITY_KEYS = [
    'nativephp.app_id',
    'nativephp.author',
    'nativephp.copyright',
    'nativephp.description',
    'nativephp.website',
];

// The framework's own scaffold values. Shipping one means the artifact claims
// to be someone else's app, which no amount of code signing can repair — a
// notarised bundle identified as com.nativephp.app is a correctly signed lie.
const DESKTOP_IDENTITY_PLACEHOLDERS = [
    'com.nativephp.app',
    'An awesome app built with NativePHP',
    'https://nativephp.com',
    'NativePHP',
];

it('gives the desktop bundle an identity of its own', function (): void {
    /** @var Config $config */
    $config = app(Config::class);

    $offenders = [];
    foreach (DESKTOP_IDENTITY_KEYS as $key) {
        $value = $config->get($key);

        if (! is_string($value) || trim($value) === '') {
            $offenders[] = $key.' is not set';

            continue;
        }

        if (in_array($value, DESKTOP_IDENTITY_PLACEHOLDERS, true)) {
            $offenders[] = $key.' is still the NativePHP placeholder '.$value;
        }
    }

    expect($offenders)->toBe(
        [],
        'These values ship inside the desktop artifact and identify it to the operating system, the '
        .'installer and the update feed. A placeholder here is not cosmetic — it is the wrong identity '
        .'on a signed binary. Set a real value as the config default rather than in .env, because the '
        ."release build copies .env.example and an empty variable there overrides a good default.\n  "
        .implode("\n  ", $offenders),
    );
});

// The mobile root pins its own id and guards it in PackageAndroidCommand. The
// two must not collide: a shared id would make the phone and the desktop the
// same application to the update feed and to the OS.
it('keeps the desktop and mobile bundle ids distinct', function (): void {
    /** @var Config $config */
    $config = app(Config::class);

    $desktop = (string) $config->get('nativephp.app_id');

    /** @var array{app_id: string} $mobileConfig */
    $mobileConfig = require base_path('mobile-app/config/nativephp.php');

    expect($desktop)->not->toBe('', 'The desktop bundle id is unset, so the comparison below would pass on two empty strings.');
    expect($mobileConfig['app_id'])->not->toBe('', 'The mobile bundle id is unset, so the comparison below would pass on two empty strings.');

    expect($desktop)->not->toBe(
        $mobileConfig['app_id'],
        'The phone and the desktop ship the same bundle id ('.$desktop.'), so they are one application to the '.
        'operating system and to the update feed: the installer for one can replace the other, and a notification '.
        'raised by one is attributed to whichever registered the id last.'
    );
});
