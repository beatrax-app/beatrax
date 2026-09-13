<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BrowserEnrollmentAuthorizer;
use Modules\Auth\Internal\Lock\FreshPinProof;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\SecretShield;

// The WebAuthn row is `secret || wrapped_key` and a biometric alone opens it
// afterwards, for as long as it exists -- the same bargain the OS vault entry
// makes, and the same one #491 refused to let a tap buy. It cost an unlocked
// session, which is the state the settings screen is only ever reached in, so
// it cost nothing. The ceremony cannot finish in the request that takes the
// PIN, so what crosses the gap is a proof of it: single-use, deadlined, and
// belonging to the account that typed it.

function browserProofUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('proof-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    test()->actingAs($user);
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'proof-pass');

    return $user;
}

// The container default is the pass-through shield, which refuses the browser
// road before anything else does. These are about the road that is open.
function browserProofShield(): void
{
    app()->instance(SecretShield::class, new class implements SecretShield
    {
        public function protect(string $plaintext): string
        {
            return strrev($plaintext);
        }

        public function reveal(string $shielded): string
        {
            return strrev($shielded);
        }

        public function protectsAtRest(): bool
        {
            return true;
        }
    });
}

it('refuses the enrolment POST when no PIN was proved', function (): void {
    browserProofUser('browser-proof-none');
    browserProofShield();

    test()->postJson('/lock/biometric/enroll', ['id' => 'unused'])
        ->assertForbidden()
        ->assertJsonPath('enrolled', false)
        ->assertJsonPath('error', 'pin_not_proved');
});

it('mints no proof for a wrong PIN', function (): void {
    $user = browserProofUser('browser-proof-wrong');
    /** @var Session $session */
    $session = app(Session::class);

    expect(app(BrowserEnrollmentAuthorizer::class)->authorize((int) $user->id, '999999', $session))->toBeFalse()
        ->and(app(FreshPinProof::class)->consume($session, (int) $user->id))->toBeFalse();
});

it('mints no proof for an empty box', function (): void {
    $user = browserProofUser('browser-proof-empty');
    /** @var Session $session */
    $session = app(Session::class);

    expect(app(BrowserEnrollmentAuthorizer::class)->authorize((int) $user->id, '', $session))->toBeFalse()
        ->and(app(FreshPinProof::class)->consume($session, (int) $user->id))->toBeFalse();
});

it('mints a proof the right PIN can spend exactly once', function (): void {
    $user = browserProofUser('browser-proof-once');
    /** @var Session $session */
    $session = app(Session::class);

    expect(app(BrowserEnrollmentAuthorizer::class)->authorize((int) $user->id, '123456', $session))->toBeTrue();

    $proof = app(FreshPinProof::class);

    expect($proof->consume($session, (int) $user->id))->toBeTrue()
        ->and($proof->consume($session, (int) $user->id))->toBeFalse();
});

it('will not let one account spend the proof another typed for', function (): void {
    $owner = browserProofUser('browser-proof-owner');
    /** @var Session $session */
    $session = app(Session::class);

    app(BrowserEnrollmentAuthorizer::class)->authorize((int) $owner->id, '123456', $session);

    $proof = app(FreshPinProof::class);

    expect($proof->consume($session, (int) $owner->id + 1))->toBeFalse()
        // Drained on the refusal too: a read that puts it back is a read the
        // right account can be raced to.
        ->and($proof->consume($session, (int) $owner->id))->toBeFalse();
});

it('will not let a proof outlive the ceremony it was typed for', function (): void {
    $user = browserProofUser('browser-proof-stale');
    /** @var Session $session */
    $session = app(Session::class);

    app(BrowserEnrollmentAuthorizer::class)->authorize((int) $user->id, '123456', $session);

    $clock = Mockery::mock(Clock::class);
    $clock->shouldReceive('now')->andReturn(CarbonImmutable::now()->addSeconds(FreshPinProof::LIFETIME_SECONDS + 1));

    expect((new FreshPinProof($clock))->consume($session, (int) $user->id))->toBeFalse();
});

it('leaves no copy of the data key behind for the ceremony to pick up', function (): void {
    $user = browserProofUser('browser-proof-nokey');
    /** @var Session $session */
    $session = app(Session::class);

    app(BrowserEnrollmentAuthorizer::class)->authorize((int) $user->id, '123456', $session);

    // The wrap happens a round trip later and reads the key from the custodian
    // at that moment, so what waits in the session is a claim and not a secret:
    // the account it belongs to, and the moment it stops being true.
    $held = $session->get('beatrax_fresh_pin_proof');

    expect($held)->toBeArray()
        ->and(array_keys((array) $held))->toBe(['user_id', 'expires_at'])
        ->and($held['user_id'])->toBe((int) $user->id)
        ->and($held['expires_at'])->toBeString();
});
