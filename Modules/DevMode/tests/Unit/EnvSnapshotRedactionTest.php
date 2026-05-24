<?php

declare(strict_types=1);

use Modules\DevMode\Internal\System\ConfigFlattener;

/*
 * ConfigFlattener invariants for the env + effective-config
 * snapshot redaction surface.
 *
 * Covers:
 *   - flatten() walks nested arrays into dot-keys.
 *   - redactSecretSuffixes() masks values for keys matching the
 *     denylist (*password*, *secret*, *key, *token*) with
 *     [REDACTED].
 *   - Non-matching keys keep their plain value (e.g.
 *     BEATRAX_DEV_MODE, BEATRAX_RUNTIME).
 *   - Empty / nested / scalar-leaf shapes handled.
 */

it('flattens nested arrays into dot-key shape', function (): void {
    $flattener = new ConfigFlattener;

    $flat = $flattener->flatten([
        'app' => [
            'name' => 'beatrax',
            'env' => 'testing',
            'providers' => ['CoreServiceProvider', 'AuthServiceProvider'],
        ],
        'session' => ['driver' => 'array'],
    ]);

    expect($flat)->toHaveKey('app.name');
    expect($flat['app.name'])->toBe('beatrax');
    expect($flat['app.env'])->toBe('testing');
    expect($flat['session.driver'])->toBe('array');
});

it('masks values for keys ending with password / secret / key / token suffixes (denylist)', function (): void {
    $flattener = new ConfigFlattener;

    $redacted = $flattener->redactSecretSuffixes([
        'app.key' => 'base64:abcdefghi=',
        'app.cipher' => 'AES-256-CBC',
        'mail.password' => 'super-mail-secret',
        'auth.oauth_secret' => 'gho_xxxxxx',
        'queue.access_token' => 'eyJhbGc...',
        'beatrax.dev_mode' => true,
        'app.name' => 'beatrax',
    ]);

    expect($redacted['app.key'])->toBe('[REDACTED]');
    expect($redacted['mail.password'])->toBe('[REDACTED]');
    expect($redacted['auth.oauth_secret'])->toBe('[REDACTED]');
    expect($redacted['queue.access_token'])->toBe('[REDACTED]');
    // Plain values stay readable.
    expect($redacted['app.cipher'])->toBe('AES-256-CBC');
    expect($redacted['beatrax.dev_mode'])->toBeTrue();
    expect($redacted['app.name'])->toBe('beatrax');
});

it('preserves BEATRAX_DEV_MODE in plain text (Q4 resolution — only secret-suffix keys are masked)', function (): void {
    $flattener = new ConfigFlattener;

    $redacted = $flattener->redactSecretSuffixes([
        'BEATRAX_DEV_MODE' => 'true',
        'BEATRAX_OAUTH_SECRET' => 'sensitive',
    ]);

    expect($redacted['BEATRAX_DEV_MODE'])->toBe('true');
    expect($redacted['BEATRAX_OAUTH_SECRET'])->toBe('[REDACTED]');
});

it('handles deeply nested arrays', function (): void {
    $flattener = new ConfigFlattener;

    $flat = $flattener->flatten([
        'logging' => [
            'channels' => [
                'daily' => [
                    'driver' => 'daily',
                    'days' => 14,
                ],
            ],
        ],
    ]);

    expect($flat['logging.channels.daily.driver'])->toBe('daily');
    expect($flat['logging.channels.daily.days'])->toBe(14);
});

it('returns empty array when given an empty config', function (): void {
    $flattener = new ConfigFlattener;

    expect($flattener->flatten([]))->toBe([]);
    expect($flattener->redactSecretSuffixes([]))->toBe([]);
});

it('redacts case-insensitively (Auth APP_KEY masks just like app.key)', function (): void {
    $flattener = new ConfigFlattener;

    $redacted = $flattener->redactSecretSuffixes([
        'APP_KEY' => 'base64:abc=',
        'OAUTH_TOKEN' => 'eyJ.123.foo',
        'DATABASE_PASSWORD' => 'pw',
    ]);

    expect($redacted['APP_KEY'])->toBe('[REDACTED]');
    expect($redacted['OAUTH_TOKEN'])->toBe('[REDACTED]');
    expect($redacted['DATABASE_PASSWORD'])->toBe('[REDACTED]');
});
