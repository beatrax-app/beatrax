<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\PublisherManifestFetcher;
use Modules\Core\Public\Services\ElectronUpdateChannel;
use Modules\Core\Public\Services\SystemClock;
use Modules\Core\Tests\Support\EuvRecordingLogger;
use Psr\Log\NullLogger;

function makeChannelWithPublicKeyHex(string $publicKeyHex): ElectronUpdateChannel
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $config = new Repository([
        'auto_update' => [
            'publisher_public_key_hex' => $publicKeyHex,
            'update_channel' => 'stable',
        ],
    ]);

    return new ElectronUpdateChannel(
        $db,
        new NullLogger,
        new SystemClock,
        $config,
    );
}

/**
 * @return array{secret: string, public: string, public_hex: string}
 */
function generateEd25519Fixture(): array
{
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);

    return [
        'secret' => $secret,
        'public' => $public,
        'public_hex' => bin2hex($public),
    ];
}

it('returns true when verifying a manifest body against its own detached signature', function (): void {
    $fixture = generateEd25519Fixture();
    $manifestBody = "version: 0.1.1-rc.1\nsha512: abc123\n";
    $signature = sodium_crypto_sign_detached($manifestBody, $fixture['secret']);

    $channel = makeChannelWithPublicKeyHex($fixture['public_hex']);

    expect($channel->verifyManifest($manifestBody, $signature))->toBeTrue();
});

it('returns false when the manifest body has been tampered (single byte flipped) with the original signature', function (): void {
    $fixture = generateEd25519Fixture();
    $manifestBody = "version: 0.1.1-rc.1\nsha512: abc123\n";
    $signature = sodium_crypto_sign_detached($manifestBody, $fixture['secret']);

    // Flip a single byte deep in the body — sha512 line, last hex digit.
    $tamperedBody = substr_replace($manifestBody, '4', strpos($manifestBody, 'abc123') + 5, 1);
    expect($tamperedBody)->not->toBe($manifestBody);

    $channel = makeChannelWithPublicKeyHex($fixture['public_hex']);

    expect($channel->verifyManifest($tamperedBody, $signature))->toBeFalse();
});

it('returns false when the signature has been tampered (single byte flipped) with the original manifest', function (): void {
    $fixture = generateEd25519Fixture();
    $manifestBody = "version: 0.1.1-rc.1\nsha512: abc123\n";
    $signature = sodium_crypto_sign_detached($manifestBody, $fixture['secret']);

    $tamperedSig = ($signature[0] === 'a' ? 'b' : 'a').substr($signature, 1);
    expect($tamperedSig)->not->toBe($signature);

    $channel = makeChannelWithPublicKeyHex($fixture['public_hex']);

    expect($channel->verifyManifest($manifestBody, $tamperedSig))->toBeFalse();
});

it('returns false on malformed signature length without throwing to callers', function (): void {
    $fixture = generateEd25519Fixture();
    $manifestBody = "version: 0.1.1-rc.1\n";

    // Not the 64-byte Ed25519 signature size, so libsodium raises internally;
    // verifyManifest must swallow it rather than crash the poll loop.
    $channel = makeChannelWithPublicKeyHex($fixture['public_hex']);

    expect($channel->verifyManifest($manifestBody, 'not-a-valid-signature'))->toBeFalse();
});

it('poll() returns null and logs at warning level when the fetched manifest signature does not verify', function (): void {
    $fixture = generateEd25519Fixture();
    $manifestBody = "version: 0.1.1-rc.1\nsha512: deadbeef\n";
    $goodSignature = sodium_crypto_sign_detached($manifestBody, $fixture['secret']);
    $tamperedSig = ($goodSignature[0] === "\x00" ? "\x01" : "\x00").substr($goodSignature, 1);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $logger = new class extends NullLogger
    {
        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        public array $messages = [];

        public function warning(string|Stringable $message, array $context = []): void
        {
            $this->messages[] = ['level' => 'warning', 'message' => (string) $message, 'context' => $context];
        }
    };
    $config = new Repository([
        'auto_update' => [
            'publisher_public_key_hex' => $fixture['public_hex'],
            'update_channel' => 'stable',
        ],
    ]);

    $channel = new ElectronUpdateChannel($db, $logger, new SystemClock, $config);

    $fetcher = new class($manifestBody, $tamperedSig) implements PublisherManifestFetcher
    {
        public function __construct(
            private readonly string $body,
            private readonly string $signature,
        ) {}

        public function fetch(string $channel): ?array
        {
            return [
                'body' => $this->body,
                'signature' => $this->signature,
                'latest_version' => '0.1.1-rc.1',
                'sha512_hex' => str_repeat('0', 128),
                'published_at' => CarbonImmutable::now(),
            ];
        }
    };

    expect($channel->poll($fetcher))->toBeNull();
    expect($logger->messages)->not->toBeEmpty();
    expect($logger->messages[0]['message'])->toContain('invalid Ed25519 signature');
});

it('poll() returns null without warning when the fetcher reports no update is available', function (): void {
    $fixture = generateEd25519Fixture();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $config = new Repository([
        'auto_update' => [
            'publisher_public_key_hex' => $fixture['public_hex'],
            'update_channel' => 'stable',
        ],
    ]);

    $channel = new ElectronUpdateChannel($db, new NullLogger, new SystemClock, $config);

    $fetcher = new class implements PublisherManifestFetcher
    {
        public function fetch(string $channel): ?array
        {
            return null;
        }
    };

    expect($channel->poll($fetcher))->toBeNull();
});

it('poll() returns a populated DTO when the fetched manifest verifies cleanly', function (): void {
    $fixture = generateEd25519Fixture();
    $manifestBody = "version: 0.1.1-rc.1\nsha512: feedface\n";
    $signature = sodium_crypto_sign_detached($manifestBody, $fixture['secret']);
    $publishedAt = CarbonImmutable::create(2026, 6, 1, 12, 0, 0);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $config = new Repository([
        'auto_update' => [
            'publisher_public_key_hex' => $fixture['public_hex'],
            'update_channel' => 'preview',
        ],
    ]);

    $channel = new ElectronUpdateChannel($db, new NullLogger, new SystemClock, $config);

    $fetcher = new class($manifestBody, $signature, $publishedAt) implements PublisherManifestFetcher
    {
        public function __construct(
            private readonly string $body,
            private readonly string $signature,
            private readonly CarbonImmutable $publishedAt,
        ) {}

        public function fetch(string $channel): ?array
        {
            return [
                'body' => $this->body,
                'signature' => $this->signature,
                'latest_version' => '0.1.1-rc.1',
                'sha512_hex' => str_repeat('a', 128),
                'published_at' => $this->publishedAt,
            ];
        }
    };

    $dto = $channel->poll($fetcher);

    expect($dto)->not->toBeNull();
    expect($dto?->latestVersion)->toBe('0.1.1-rc.1');
    expect($dto?->sha512Hex)->toBe(str_repeat('a', 128));
    expect($dto?->publishedAt->equalTo($publishedAt))->toBeTrue();
    expect($dto?->channel)->toBe('preview');
});

// A missing publisher key is a mis-shipped build; a manifest that fails to
// verify is a manifest to distrust. The warning is what tells them apart in
// the log, so it is asserted rather than assumed.

it('refuses to verify, and says why, when no publisher key is configured', function (mixed $configured): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $logger = new EuvRecordingLogger;
    $channel = new ElectronUpdateChannel(
        $db,
        $logger,
        new SystemClock,
        new Repository(['auto_update' => ['publisher_public_key_hex' => $configured, 'update_channel' => 'stable']]),
    );

    expect($channel->verifyManifest('body', str_repeat('s', 64)))->toBeFalse()
        ->and($logger->warnings)->toContain('ElectronUpdateChannel: missing or invalid publisher public key configuration.');
})->with([
    'absent' => [null],
    'empty' => [''],
    'not a string' => [12345],
]);

// The signature is checked before the configuration is read, so a caller
// passing none never provokes a warning about an install that may be fine.
it('refuses an empty signature without complaining about the configuration', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $logger = new EuvRecordingLogger;
    $channel = new ElectronUpdateChannel(
        $db,
        $logger,
        new SystemClock,
        new Repository(['auto_update' => ['publisher_public_key_hex' => null, 'update_channel' => 'stable']]),
    );

    expect($channel->verifyManifest('body', ''))->toBeFalse()
        ->and($logger->warnings)->toBe([]);
});
