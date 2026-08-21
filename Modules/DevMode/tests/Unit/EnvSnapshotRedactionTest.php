<?php

declare(strict_types=1);

use Modules\DevMode\Internal\System\ConfigFlattener;

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

it('masks app.previous_keys — the retired APP_KEY list still decrypts data at rest', function (): void {
    $flattener = new ConfigFlattener;

    // 'app.previous_keys' does not end in 'key', and flatten() folds the
    // scalar list into a single json_encode'd leaf — that combination is
    // exactly the shape that leaked, so the test runs both steps.
    $flat = $flattener->flatten([
        'app' => [
            'key' => 'base64:current=',
            'previous_keys' => ['base64:retired-one=', 'base64:retired-two='],
        ],
    ]);

    $redacted = $flattener->redactSecretSuffixes($flat);

    expect($redacted['app.previous_keys'])->toBe('[REDACTED]');
    expect($redacted['app.key'])->toBe('[REDACTED]');
});

it('masks passphrase and credential keys the singular suffixes miss', function (): void {
    $flattener = new ConfigFlattener;

    $redacted = $flattener->redactSecretSuffixes([
        'backup.passphrase' => 'correct horse battery staple',
        'sync.relay_credentials' => 'user:pass',
        'app.kind' => 'desktop',
    ]);

    expect($redacted['backup.passphrase'])->toBe('[REDACTED]');
    expect($redacted['sync.relay_credentials'])->toBe('[REDACTED]');
    // 'kind' is the near-miss a substring check would have masked.
    expect($redacted['app.kind'])->toBe('desktop');
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
