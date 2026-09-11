<?php

declare(strict_types=1);

// `SESSION_ENCRYPT=false` sat in four shipped templates reading as a decision,
// and config/session.php never looked at it. Harmless while the config holds a
// literal; the day somebody "fixes" the config to honour the key, the app-lock
// data key stops being encrypted at rest on every shape with no OS key store,
// where the session payload is the only place it lives.
/**
 * @link ../../.docs/features/auth/app-lock-data-key-lifetime.md
 */
it('keeps session encryption on, and out of reach of a deployment', function (): void {
    $source = (string) file_get_contents(base_path('config/session.php'));

    expect(strlen($source))->toBeGreaterThan(
        200,
        'config/session.php read back '.strlen($source).' bytes, too few for the rules below to have looked.'
    );

    expect($source)->toMatch(
        '/^\s*\'encrypt\'\s*=>\s*true,\s*$/m',
        "config/session.php no longer states 'encrypt' => true as a literal. On any shape where ".
        'KeyCustodian is not rebound, the session payload holds the raw app-lock data key.'
    );

    expect($source)->not->toMatch('/\'encrypt\'\s*=>\s*env\(/');
});

// The positive control: the same read finds the keys that ARE a deployment's,
// so a source that came back unreadable cannot pass the rule above in silence.
it('leaves the keys a deployment does own alone', function (): void {
    $source = (string) file_get_contents(base_path('config/session.php'));

    expect($source)->toContain("env('SESSION_DRIVER'")
        ->toContain("env('SESSION_CONNECTION'");
});

it('names the dead key in no shipped template', function (): void {
    $offenders = [];

    foreach (['.env.example', '.env.bundled', 'mobile-app/.env.example', 'mobile-app/.env.bundled'] as $relative) {
        $path = base_path($relative);

        if (! is_file($path)) {
            continue;
        }

        if (str_contains((string) file_get_contents($path), 'SESSION_ENCRYPT')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe(
        [],
        'A template names SESSION_ENCRYPT, which config/session.php reads nowhere. A key that '.
        'changes nothing reads as a decision somebody may act on: '.implode(', ', $offenders)
    );
});
