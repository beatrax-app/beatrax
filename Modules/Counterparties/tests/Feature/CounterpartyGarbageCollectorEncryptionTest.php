<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Counterparties\Internal\Jobs\CounterpartyGarbageCollectorJob;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// The orphan predicate compares counterparties.merchant_name to the
// always-plaintext merchant_aliases.friendly_name in SQL, and under encryption
// those can never byte-match — which made an alias-protected row look prunable.
// The job's only real dispatch origin is the daily schedule, so no KEK.

function cgeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function cgeCounterparty(int $userId, string $slug, string $displayName, ?string $merchantName = null): int
{
    $now = now()->toDateTimeString();
    $id = DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => $displayName,
        'iban' => null,
        'merchant_name' => $merchantName,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $id;
}

function cgeAlias(int $userId, string $pattern, string $friendlyName): void
{
    DB::table('merchant_aliases')->insert([
        'user_id' => $userId,
        'pattern' => $pattern,
        'generalized_pattern' => strtolower($friendlyName),
        'friendly_name' => $friendlyName,
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('skips the merchant_name-dependent prune with a logged warning when the KEK is unavailable for an encrypted user — an alias-protected counterparty survives; the merchant_name-IS-NULL half still prunes', function (): void {
    $user = cgeUser('cge-kek-absent');
    $session = $this->enablesEncryptionForUser($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    // Alias-protected and with no recent transaction: the shape that was
    // wrongly pruned under encryption.
    $encryptedSpotify = $codec->encryptValue('counterparties', 'merchant_name', 'Spotify', $user->id, $session);
    expect($encryptedSpotify)->not->toBe('Spotify');
    $aliasProtectedId = cgeCounterparty($user->id, 'cge-spotify', 'Spotify', $encryptedSpotify);
    cgeAlias($user->id, 'SPOTIFY AB', 'Spotify');

    // Genuinely orphaned, but with a ciphertext merchant_name the job cannot
    // read without a KEK — so it survives too. Preserving a row we cannot
    // evaluate beats a wrongful prune.
    $encryptedOldShop = $codec->encryptValue('counterparties', 'merchant_name', 'Old Shop', $user->id, $session);
    $noAliasCiphertextId = cgeCounterparty($user->id, 'cge-old-shop', 'Old Shop', $encryptedOldShop);

    // A NULL merchant_name can hold no alias, so this half of the predicate
    // stays safe with or without a KEK.
    $nullMerchantOrphanId = cgeCounterparty($user->id, 'cge-null-orphan', 'NL00IBAN000000001');

    // Withheld after the ciphertext fixtures are written but before the job
    // runs — the daemon's real shape.
    $this->app->make(AppLockKeyService::class)->withhold($session);

    /** @var LoggerInterface&MockInterface $logger */
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($user): bool {
            return str_contains($message, 'no app-lock KEK available')
                && ($context['user_id'] ?? null) === $user->id
                && ($context['skipped_count'] ?? null) === 2;
        });

    $job = new CounterpartyGarbageCollectorJob($user->id);
    $job->handle(
        $this->app->make(DatabaseManager::class),
        $this->app->make(Clock::class),
        $session,
        $this->app->make(AppLockKeyService::class),
        $this->app->make(EncryptionMigrationService::class),
        $codec,
        $logger,
    );

    expect(DB::table('counterparties')->where('id', $aliasProtectedId)->count())->toBe(1);
    expect(DB::table('counterparties')->where('id', $noAliasCiphertextId)->count())->toBe(1);
    expect(DB::table('counterparties')->where('id', $nullMerchantOrphanId)->count())->toBe(0);
});

it('decrypts merchant_name in PHP and compares against alias friendly_names when a KEK IS available for an encrypted user — alias-protected survives, genuinely-orphaned still prunes', function (): void {
    $user = cgeUser('cge-kek-present');
    $session = $this->enablesEncryptionForUser($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $encryptedNetflix = $codec->encryptValue('counterparties', 'merchant_name', 'Netflix', $user->id, $session);
    $aliasProtectedId = cgeCounterparty($user->id, 'cge-netflix', 'Netflix', $encryptedNetflix);
    cgeAlias($user->id, 'NETFLIX.COM', 'Netflix');

    $encryptedDefunct = $codec->encryptValue('counterparties', 'merchant_name', 'Defunct Store', $user->id, $session);
    $orphanId = cgeCounterparty($user->id, 'cge-defunct', 'Defunct Store', $encryptedDefunct);

    $job = new CounterpartyGarbageCollectorJob($user->id);
    $job->handle(
        $this->app->make(DatabaseManager::class),
        $this->app->make(Clock::class),
        $session,
        $this->app->make(AppLockKeyService::class),
        $this->app->make(EncryptionMigrationService::class),
        $codec,
        $this->app->make(LoggerInterface::class),
    );

    expect(DB::table('counterparties')->where('id', $aliasProtectedId)->count())->toBe(1);
    expect(DB::table('counterparties')->where('id', $orphanId)->count())->toBe(0);
});

it('leaves a non-encrypted user\'s prune completely unchanged (regression lock)', function (): void {
    $user = cgeUser('cge-plaintext');
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $aliasProtectedId = cgeCounterparty($user->id, 'cge-plain-spotify', 'Spotify', 'Spotify');
    cgeAlias($user->id, 'SPOTIFY AB', 'Spotify');

    $orphanId = cgeCounterparty($user->id, 'cge-plain-orphan', 'Stale Merchant');

    $job = new CounterpartyGarbageCollectorJob($user->id);
    $job->handle(
        $this->app->make(DatabaseManager::class),
        $this->app->make(Clock::class),
        $session,
        $this->app->make(AppLockKeyService::class),
        $this->app->make(EncryptionMigrationService::class),
        $this->app->make(SensitiveColumnCodec::class),
        $this->app->make(LoggerInterface::class),
    );

    expect(DB::table('counterparties')->where('id', $aliasProtectedId)->count())->toBe(1);
    expect(DB::table('counterparties')->where('id', $orphanId)->count())->toBe(0);
});

it('keeps the short handle() call shape working (all crypto params default null)', function (): void {
    $user = cgeUser('cge-legacy-call');
    $aliasProtectedId = cgeCounterparty($user->id, 'cge-legacy-spotify', 'Spotify', 'Spotify');
    cgeAlias($user->id, 'SPOTIFY AB', 'Spotify');

    $job = new CounterpartyGarbageCollectorJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class), $this->app->make(Clock::class));

    expect(DB::table('counterparties')->where('id', $aliasProtectedId)->count())->toBe(1);
});
