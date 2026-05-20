<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;

/*
 * Feature coverage for RecoveryCodeAuthenticator — the shared
 * verification core: it matches a typed username + recovery code against
 * the unused user_recovery_codes rows under a row lock, stamps used_at on
 * the matching row (single-use), normalises case + formatting, returns
 * null without throwing on an unknown username, and writes a system_alerts
 * audit row for every attempt.
 */

/**
 * Signs up an owner via the real SignupAction so the stored recovery-code
 * hashes match exactly what a redemption attempt will be compared against,
 * then returns the user together with its ten plaintext codes.
 *
 * @return array{user: User, codes: list<string>}
 */
function signUpWithCodes(string $username = 'owner'): array
{
    /** @var SignupAction $signup */
    $signup = test()->app->make(SignupAction::class);

    $result = $signup($username, 'owner-password-123');

    return ['user' => $result['user'], 'codes' => $result['codesPlain']];
}

it('returns the user and stamps used_at when a valid unused code is given', function (): void {
    ['user' => $user, 'codes' => $codes] = signUpWithCodes();

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = $this->app->make(RecoveryCodeAuthenticator::class);

    $verified = $authenticator->verify('owner', $codes[0]);

    expect($verified)->not->toBeNull();
    expect($verified->id)->toBe($user->id);

    $matched = UserRecoveryCode::query()
        ->where('user_id', $user->id)
        ->whereNotNull('used_at')
        ->get();
    expect($matched)->toHaveCount(1);
});

it('returns null when an already-used code is presented a second time', function (): void {
    ['codes' => $codes] = signUpWithCodes();

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = $this->app->make(RecoveryCodeAuthenticator::class);

    expect($authenticator->verify('owner', $codes[0]))->not->toBeNull();
    expect($authenticator->verify('owner', $codes[0]))->toBeNull();
});

it('accepts a code typed lowercase and without hyphens', function (): void {
    ['codes' => $codes] = signUpWithCodes();

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = $this->app->make(RecoveryCodeAuthenticator::class);

    $scrambled = strtolower(str_replace('-', '', $codes[0]));

    expect($authenticator->verify('owner', $scrambled))->not->toBeNull();
});

it('returns null for an unknown username without throwing', function (): void {
    signUpWithCodes();

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = $this->app->make(RecoveryCodeAuthenticator::class);

    expect($authenticator->verify('nobody', 'A2BJ-XK9M-PQ7N-RX4F-V8HD'))->toBeNull();
});

it('returns null for a wrong code against a known username', function (): void {
    signUpWithCodes();

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = $this->app->make(RecoveryCodeAuthenticator::class);

    expect($authenticator->verify('owner', 'AAAA-BBBB-CCCC-DDDD-EEEE'))->toBeNull();
});

it('writes a warning system_alerts row on a successful redemption', function (): void {
    ['codes' => $codes] = signUpWithCodes();

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = $this->app->make(RecoveryCodeAuthenticator::class);

    $authenticator->verify('owner', $codes[0]);

    $alert = SystemAlert::query()
        ->where('kind', 'auth.recovery_code_consumed')
        ->first();

    expect($alert)->not->toBeNull();
    expect($alert->severity)->toBe('warning');
    expect($alert->message)->toBe('Recovery code used by owner.');
});

it('writes a critical system_alerts row on a failed redemption', function (): void {
    signUpWithCodes();

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = $this->app->make(RecoveryCodeAuthenticator::class);

    $authenticator->verify('owner', 'AAAA-BBBB-CCCC-DDDD-EEEE');

    $alert = SystemAlert::query()
        ->where('kind', 'auth.recovery_code_failed')
        ->first();

    expect($alert)->not->toBeNull();
    expect($alert->severity)->toBe('critical');
    expect($alert->message)->toBe('Failed recovery code attempt for owner.');
});

it('hashes the recovery code as the formatted five-group string', function (): void {
    // Round-trip guard: the authenticator must re-format the normalised
    // input into the exact hyphenated shape SignupAction hashed. A
    // hash-shape regression would make every redemption fail silently.
    ['user' => $user, 'codes' => $codes] = signUpWithCodes();

    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $row = UserRecoveryCode::query()->where('user_id', $user->id)->first();
    expect($hasher->check($codes[0], $row->code_hash))->toBeTrue();
});
