<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Counterparties\Internal\Jobs\CounterpartyGarbageCollectorJob;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

/*
 * 14.1-15 (CRYPT-01, Cluster 5 gap closure, daemon-only) —
 * CounterpartyGarbageCollectorJob's orphan predicate does a raw SQL
 * whereColumn equality between the encrypted counterparties.merchant_name
 * and the always-plaintext merchant_aliases.friendly_name. Under
 * encryption the two can never byte-match, so the alias-preservation
 * branch always looks like "no matching alias" — an alias-protected
 * counterparty wrongly becomes prunable purely because its merchant_name
 * is now ciphertext.
 *
 * This job's ONLY dispatch origin is the routes/console.php daily
 * Schedule::call fan-out — a real queue worker with no live Session, so
 * in production it NEVER has a KEK for an encrypted user. This suite
 * proves the fix across all three shapes:
 *
 *  1. Encrypted, KEK absent (the only shape this job's real dispatch
 *     origin ever produces): the merchant_name-dependent half of the
 *     predicate is SKIPPED with a logged warning — an alias-protected
 *     counterparty is NEVER wrongly pruned; the merchant_name-IS-NULL
 *     half (always plaintext-safe) keeps pruning normally.
 *  2. Encrypted, KEK present (kept symmetric with plan 08's pattern for
 *     a future request-bound dispatch origin): the fix decrypts each
 *     candidate's merchant_name in PHP and compares against the user's
 *     alias friendly_names in PHP — alias-protected rows survive,
 *     genuinely-orphaned rows still prune.
 *  3. Non-encrypted: completely unchanged (regression lock).
 */

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

    // Alias-protected: merchant_name ciphertext decrypts to "Spotify",
    // which a merchant_aliases row's friendly_name also names. No recent
    // transaction. Pre-fix this is WRONGLY pruned under encryption; this
    // test proves it now survives.
    $encryptedSpotify = $codec->encryptValue('counterparties', 'merchant_name', 'Spotify', $user->id, $session);
    expect($encryptedSpotify)->not->toBe('Spotify');
    $aliasProtectedId = cgeCounterparty($user->id, 'cge-spotify', 'Spotify', $encryptedSpotify);
    cgeAlias($user->id, 'SPOTIFY AB', 'Spotify');

    // Genuinely orphaned with a NON-NULL (ciphertext) merchant_name and NO
    // matching alias. Under the "no KEK -> skip the whole
    // merchant_name-IS-NOT-NULL half" posture this row is conservatively
    // preserved too (preserve-data-on-uncertainty) rather than risking a
    // wrongful prune of a row we cannot evaluate.
    $encryptedOldShop = $codec->encryptValue('counterparties', 'merchant_name', 'Old Shop', $user->id, $session);
    $noAliasCiphertextId = cgeCounterparty($user->id, 'cge-old-shop', 'Old Shop', $encryptedOldShop);

    // Plaintext-safe orphan: merchant_name IS NULL, no recent transaction,
    // no alias possible. Always prunable, encrypted or not.
    $nullMerchantOrphanId = cgeCounterparty($user->id, 'cge-null-orphan', 'NL00IBAN000000001');

    // Simulate the real daemon dispatch shape: withhold the KEK AFTER the
    // ciphertext fixtures were written but BEFORE the job runs.
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
        $session,
        $this->app->make(AppLockKeyService::class),
        $this->app->make(EncryptionMigrationService::class),
        $this->app->make(SensitiveColumnCodec::class),
        $this->app->make(LoggerInterface::class),
    );

    expect(DB::table('counterparties')->where('id', $aliasProtectedId)->count())->toBe(1);
    expect(DB::table('counterparties')->where('id', $orphanId)->count())->toBe(0);
});

it('keeps the legacy bare 1-arg handle() test-call shape working (all crypto params default null)', function (): void {
    $user = cgeUser('cge-legacy-call');
    $aliasProtectedId = cgeCounterparty($user->id, 'cge-legacy-spotify', 'Spotify', 'Spotify');
    cgeAlias($user->id, 'SPOTIFY AB', 'Spotify');

    $job = new CounterpartyGarbageCollectorJob($user->id);
    $job->handle($this->app->make(DatabaseManager::class));

    expect(DB::table('counterparties')->where('id', $aliasProtectedId)->count())->toBe(1);
});
