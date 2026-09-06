<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\ShippedEnvironment;

// The .env a mobile build carries is copied into the bundle, so what it says is
// what the phone runs. On a mobile runtime DevConsoleBuildGate reads
// config('app.debug') and nothing else, which makes APP_DEBUG=true the whole
// distance between a store build and one that opens an artisan runner.

it('accepts the env the release workflow writes', function (): void {
    expect(ShippedEnvironment::wrongIn(
        "APP_NAME=Beatrax\nAPP_ENV=production\nAPP_KEY=base64:x\nAPP_DEBUG=false\n",
    ))->toBe([]);
});

it('names both keys and what the file carries instead', function (): void {
    expect(ShippedEnvironment::wrongIn("APP_ENV=local\nAPP_DEBUG=true\n"))
        ->toBe(['APP_ENV' => 'local', 'APP_DEBUG' => 'true']);
});

it('reads a key that is absent as absent rather than as satisfied', function (): void {
    expect(ShippedEnvironment::wrongIn("APP_ENV=production\n"))
        ->toHaveKey('APP_DEBUG')
        ->not->toHaveKey('APP_ENV');
});

// A commented key is what the template ships, and treating it as an assignment
// would read the template itself as production-ready.
it('does not read a commented line as an assignment', function (string $line): void {
    expect(ShippedEnvironment::wrongIn("APP_ENV=production\n".$line))->toHaveKey('APP_DEBUG');
})->with([
    'hash at the margin' => "# APP_DEBUG=false\n",
    'hash after indentation' => "   # APP_DEBUG=false\n",
]);

it('reads a quoted or padded value as the value it is', function (string $written): void {
    expect(ShippedEnvironment::wrongIn("APP_ENV=production\nAPP_DEBUG={$written}\n"))->toBe([]);
})->with([
    'double quoted' => '"false"',
    'single quoted' => "'false'",
    'shouted' => 'FALSE',
]);

// Dotenv's immutable loader refuses to overwrite a name it has already written,
// so a later APP_ENV never reaches config(). A check reading the last one would
// pass a file the application reads as local.
it('answers on the first assignment, which is the one that reaches config', function (): void {
    expect(ShippedEnvironment::wrongIn("APP_ENV=local\nAPP_DEBUG=false\nAPP_ENV=production\n"))
        ->toBe(['APP_ENV' => 'local']);
});

it('is not satisfied by a longer key that ends in the right name', function (): void {
    expect(ShippedEnvironment::wrongIn("BEATRAX_APP_ENV=production\nAPP_DEBUG=false\n"))
        ->toHaveKey('APP_ENV');
});

it('states the value each key has to carry, so a refusal can quote it', function (): void {
    expect(ShippedEnvironment::required())->toBe(['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);
});

// The level, not the key: an operator may reasonably ship `info`, and may not
// ship the one that writes a personal ledger's rows to a phone's disk.
it('refuses the log level a shipped bundle may not carry', function (): void {
    expect(ShippedEnvironment::wrongIn("APP_ENV=production\nAPP_DEBUG=false\nLOG_LEVEL=debug\n"))
        ->toBe(['LOG_LEVEL' => 'debug']);
});

it('accepts a log level that is merely talkative', function (string $level): void {
    expect(ShippedEnvironment::wrongIn("APP_ENV=production\nAPP_DEBUG=false\nLOG_LEVEL={$level}\n"))
        ->toBe([]);
})->with(['info', 'warning', 'error']);

it('states the value each key may not carry, so a refusal can quote it', function (): void {
    expect(ShippedEnvironment::refused())->toBe(['LOG_LEVEL' => 'debug']);
});
