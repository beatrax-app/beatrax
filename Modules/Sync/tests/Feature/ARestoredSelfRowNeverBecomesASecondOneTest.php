<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

// Restoring the self row for a key file still on disk is keyed on device_id so
// a surviving row is refreshed rather than duplicated — but the test in front
// of the insert is not what enforces that, device_registry_user_device_idx is.
// Two restores overlapping left the loser raising out of the sync-enable path,
// and the one thing that must never happen here is the insert succeeding: a
// second self row is a second identity to every peer reading deviceKeys().

function ctaSelfUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'cta-self-'.$suffix.'-'.bin2hex(random_bytes(3)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

// The other restore, committed at the instant this one has read "no row".
/** @param array<string, mixed> $row */
function ctaSelfInjectAfterRead(array $row): void
{
    $done = false;

    DB::listen(function ($query) use (&$done, $row): void {
        if ($done || ! str_contains($query->sql, 'device_registry')) {
            return;
        }
        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }

        $done = true;
        DB::table('device_registry')->insert($row);
    });
}

beforeEach(function (): void {
    $this->user = ctaSelfUser('restore');
    $this->actingAs($this->user);

    /** @var Session $session */
    $session = app(Session::class);
    $this->session = $session;

    $this->identity = app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);

    $selfRow = DB::table('device_registry')
        ->where('user_id', $this->user->id)
        ->where('device_id', $this->identity->deviceId)
        ->first();

    $this->detectedName = (string) $selfRow->name;

    // The state restoreSelfRow exists for: the key file survives, the row does
    // not. Everything below is about what the second restore does with it.
    DB::table('device_registry')->where('user_id', $this->user->id)->delete();
});

it('converges on the row the other restore wrote instead of adding a second self row', function (): void {
    ctaSelfInjectAfterRead([
        'user_id' => $this->user->id,
        'device_id' => $this->identity->deviceId,
        'name' => 'the-other-restore',
        'ed25519_public_key_hex' => $this->identity->ed25519PublicKeyHex,
        'x25519_public_key_hex' => $this->identity->x25519PublicKeyHex,
        'safety_number_words' => 'stale words',
        'is_self' => 1,
        'paired_at' => '2026-01-01T00:00:00Z',
        'confirmed_at' => null,
        'last_seen_at' => null,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $this->session);

    $rows = DB::table('device_registry')
        ->where('user_id', $this->user->id)
        ->where('device_id', $this->identity->deviceId)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->name)->toBe($this->detectedName)
        ->and($rows[0]->safety_number_words)->toBe('')
        ->and((int) $rows[0]->is_self)->toBe(1)
        ->and($rows[0]->confirmed_at)->not->toBeNull()
        ->and($rows[0]->ed25519_public_key_hex)->toBe($this->identity->ed25519PublicKeyHex);
});

it('restores the self row unchanged when no second restore is in flight', function (): void {
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $this->session);

    $rows = DB::table('device_registry')
        ->where('user_id', $this->user->id)
        ->where('device_id', $this->identity->deviceId)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->name)->toBe($this->detectedName)
        ->and($rows[0]->safety_number_words)->toBe('')
        ->and((int) $rows[0]->is_self)->toBe(1)
        ->and($rows[0]->confirmed_at)->not->toBeNull();
});

it('keeps one identity across an off-and-on, so nothing signed becomes unverifiable', function (): void {
    $again = app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $this->session);

    expect($again->deviceId)->toBe($this->identity->deviceId)
        ->and($again->ed25519PublicKeyHex)->toBe($this->identity->ed25519PublicKeyHex)
        ->and(DB::table('device_registry')->where('user_id', $this->user->id)->count())->toBe(1);
});
