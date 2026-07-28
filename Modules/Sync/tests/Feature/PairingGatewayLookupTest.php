<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

/**
 * The read-side of PairingGateway, driven against a real database.
 *
 * Its collaborators are all final, so the decision points cannot be reached
 * with doubles — these go through the container with the real services, which
 * is also the only way the user scoping gets exercised for what it is: a
 * pairing token is the thing that admits a new device to someone's account,
 * so a lookup that ignored user_id would let one account's poll observe
 * another's pairing.
 */
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->other = User::create([
        'username' => 'someone-else',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->gateway = app(PairingGateway::class);
});

function pairingToken(int $userId, string $state = 'pending'): int
{
    return (int) DB::table('pairing_tokens')->insertGetId([
        'user_id' => $userId,
        'token_hash' => hash('sha256', 'token-'.$userId.'-'.$state),
        'initiator_device_id' => 'desktop-device',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'state' => $state,
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601String(),
        'created_at' => CarbonImmutable::now()->toIso8601String(),
    ]);
}

it('reports the state a pairing token is in', function (string $state): void {
    $id = pairingToken($this->user->id, $state);

    expect($this->gateway->tokenState($id, $this->user->id))->toBe($state);
})->with(['pending', 'accepted', 'confirmed', 'expired']);

it('reports nothing for a token belonging to another account', function (): void {
    $theirs = pairingToken($this->other->id, 'accepted');

    // A poll-driven step machine calls this in a loop. Without the user_id
    // predicate it would watch a stranger's pairing advance and act on it.
    expect($this->gateway->tokenState($theirs, $this->user->id))->toBeNull();
});

it('reports nothing for a token id that does not exist', function (): void {
    expect($this->gateway->tokenState(999999, $this->user->id))->toBeNull();
});

it('refuses a word code that is not decodable rather than throwing at the caller', function (string $code): void {
    // The enter-code step feeds this straight from a text input, so malformed
    // input is the normal case, not an exceptional one.
    expect($this->gateway->acceptWordCode($code, $this->user->id, app(Session::class)))->toBeNull();
})->with([
    'empty' => [''],
    'not base32' => ['!!!!'],
    'wrong length' => ['abc'],
    'plausible but invalid' => ['zzzz-zzzz-zzzz-zzzz'],
]);
