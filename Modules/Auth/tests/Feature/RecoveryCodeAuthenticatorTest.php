<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\StoredCopy;

// The real SignupAction, so the stored hashes are exactly what a redemption
// attempt gets compared against.
/**
 * @return array{user: User, codes: list<string>}
 */
function signUpWithCodes(string $username = 'owner'): array
{
    /** @var SignupAction $signup */
    $signup = app(SignupAction::class);

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
    expect(StoredCopy::readFromParams($alert->metadata, (string) $alert->message))->toBe('Recovery code used by owner.');

    // The column itself stays a sentence. This row travels to the household's
    // other device, which may be on a build that cannot read the spec beside
    // it, and a raw envelope on that screen is the reader's problem, not ours.
    expect((string) $alert->message)->toBe('Recovery code used by owner.');
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
    expect(StoredCopy::readFromParams($alert->metadata, (string) $alert->message))->toBe('Failed recovery code attempt for owner.');
    expect((string) $alert->message)->toBe('Failed recovery code attempt for owner.');
});

it('hashes the recovery code as the formatted five-group string', function (): void {
    // The authenticator has to re-format normalised input into the exact
    // hyphenated shape that was hashed, or every redemption fails silently.
    ['user' => $user, 'codes' => $codes] = signUpWithCodes();

    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $row = UserRecoveryCode::query()->where('user_id', $user->id)->first();
    expect($hasher->check($codes[0], $row->code_hash))->toBeTrue();
});
