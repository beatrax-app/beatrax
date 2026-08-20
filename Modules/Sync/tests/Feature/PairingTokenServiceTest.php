<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingTokenService;

uses(RefreshDatabase::class);

function tokenUser(string $username = 'token-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('token-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('stores the token as a SHA-256 hash, never plaintext', function (): void {
    $user = tokenUser();

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    expect($row)->not->toBeNull();
    expect($row->token_hash)->not->toBe($token);
    expect($row->token_hash)->toBe(hash('sha256', $token));
});

it('it_rejects_already_used_token', function (): void {
    $user = tokenUser('token-used');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    $first = $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));
    expect($first)->not->toBeFalse();

    $second = $service->accept($token, (int) $user->id, 'device-resp-2', str_repeat('e', 64), str_repeat('f', 64));
    expect($second)->toBeFalse();
});

it('does not shorten the token TTL when the responder accepts early (CR-02)', function (): void {
    $user = tokenUser('token-early-accept');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Issued at 10:00:00 → TTL +10m → original expiry 10:10:00.
    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    $issued = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    $originalExpiry = CarbonImmutable::parse($issued->expires_at);

    // Responder accepts early (30s later). The +5m grace floor (10:05:30) is
    // EARLIER than the original 10:10:00 expiry — the window must not shrink.
    CarbonImmutable::setTestNow('2026-06-15 10:00:30');
    $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));

    $accepted = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    $newExpiry = CarbonImmutable::parse($accepted->expires_at);

    expect($newExpiry->greaterThanOrEqualTo($originalExpiry))->toBeTrue();
});

it('extends the token TTL when the responder accepts near the original expiry (CR-02)', function (): void {
    $user = tokenUser('token-late-accept');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Issued at 10:00:00 → original expiry 10:10:00.
    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    $issued = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    $originalExpiry = CarbonImmutable::parse($issued->expires_at);

    // Responder accepts at 10:09:00 → grace floor 10:14:00 > original → grows.
    CarbonImmutable::setTestNow('2026-06-15 10:09:00');
    $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));

    $accepted = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    $newExpiry = CarbonImmutable::parse($accepted->expires_at);

    expect($newExpiry->greaterThan($originalExpiry))->toBeTrue();
});

it('rejects an accept whose responder public key is not valid 64-char hex (WR-01)', function (): void {
    $user = tokenUser('token-bad-key');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    // A non-hex / wrong-length responder key must be rejected cleanly (false),
    // not persisted and later exploded as a SodiumException.
    $result = $service->accept($token, (int) $user->id, 'device-resp', 'not-hex', str_repeat('d', 64));

    expect($result)->toBeFalse();
});

it('prunes expired and terminal token rows on the next issue (WR-04)', function (): void {
    $user = tokenUser('token-prune');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    expect($db->connection()->table('pairing_tokens')->where('user_id', $user->id)->count())->toBe(1);

    // Time passes well past the first token's TTL, then the user re-issues.
    CarbonImmutable::setTestNow('2026-06-15 10:20:00');
    $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    $rows = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->state)->toBe('pending');
});

it('it_rejects_expired_token', function (): void {
    $user = tokenUser('token-expired');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    // Advance time well past the token TTL.
    CarbonImmutable::setTestNow('2026-06-15 11:00:00');

    $result = $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));
    expect($result)->toBeFalse();
});
