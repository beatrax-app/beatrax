<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\ElectronUpdateChannel;
use Modules\Core\Public\Services\SystemClock;
use Modules\Core\Public\Services\UpdateChannelPreference;
use Psr\Log\NullLogger;

// verifyBinary is the second layer of the auto-update integrity chain: once the
// Ed25519-signed manifest has been validated every downloaded binary is hashed
// against the SHA-512 the manifest declared, and a mismatch of a single bit has
// to stop quitAndInstall from firing.

function makeChannelForSha512(): ElectronUpdateChannel
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $config = new Repository(['auto_update' => ['publisher_public_key_hex' => str_repeat('00', 32)]]);

    return new ElectronUpdateChannel(
        $db,
        new NullLogger,
        new SystemClock,
        $config,
        app(UpdateChannelPreference::class),
    );
}

function makeChannelWithFrozenClock(CarbonImmutable $now): ElectronUpdateChannel
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $config = new Repository(['auto_update' => ['publisher_public_key_hex' => str_repeat('00', 32)]]);

    $clock = new class($now) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $now) {}

        public function now(): CarbonImmutable
        {
            return $this->now;
        }
    };

    return new ElectronUpdateChannel(
        $db,
        new NullLogger,
        $clock,
        $config,
        app(UpdateChannelPreference::class),
    );
}

it('returns true when the expected SHA-512 matches the file contents', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'beatrax-sha512-');
    file_put_contents($tmp, 'fixture-binary-payload');
    $expectedHex = hash_file('sha512', $tmp);

    $channel = makeChannelForSha512();

    try {
        expect($channel->verifyBinary($tmp, $expectedHex))->toBeTrue();
    } finally {
        unlink($tmp);
    }
});

it('returns false when one hex digit of the expected SHA-512 has been flipped', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'beatrax-sha512-');
    file_put_contents($tmp, 'fixture-binary-payload');
    $expectedHex = hash_file('sha512', $tmp);
    $tamperedHex = ($expectedHex[0] === 'a' ? 'b' : 'a').substr($expectedHex, 1);

    $channel = makeChannelForSha512();

    try {
        expect($channel->verifyBinary($tmp, $tamperedHex))->toBeFalse();
    } finally {
        unlink($tmp);
    }
});

it('returns false when the binary file is missing on disk', function (): void {
    $channel = makeChannelForSha512();
    $expectedHex = hash('sha512', 'whatever');

    expect($channel->verifyBinary('/tmp/beatrax-this-path-must-not-exist-'.bin2hex(random_bytes(8)), $expectedHex))
        ->toBeFalse();
});

it('returns true for isStale when current and latest differ and the latest was published more than 30 days ago', function (): void {
    $now = CarbonImmutable::create(2026, 6, 1, 12, 0, 0);
    $publishedAt = $now->subDays(31);

    $channel = makeChannelWithFrozenClock($now);

    expect($channel->isStale('0.1.0', '0.1.1', $publishedAt))->toBeTrue();
});

it('returns false for isStale when the latest was published less than 30 days ago', function (): void {
    $now = CarbonImmutable::create(2026, 6, 1, 12, 0, 0);
    $publishedAt = $now->subDays(15);

    $channel = makeChannelWithFrozenClock($now);

    expect($channel->isStale('0.1.0', '0.1.1', $publishedAt))->toBeFalse();
});

it('returns false for isStale when the current version already equals the latest version regardless of age', function (): void {
    $now = CarbonImmutable::create(2026, 6, 1, 12, 0, 0);
    $publishedAt = $now->subDays(120);

    $channel = makeChannelWithFrozenClock($now);

    expect($channel->isStale('0.1.1', '0.1.1', $publishedAt))->toBeFalse();
});
