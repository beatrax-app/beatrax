<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Auth\Internal\Recovery\RecoveryCodeMinter;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Auth\Public\Actions\ResetPasswordAction;
use Modules\Core\Models\User;

// /reset-password is a guest route, so both of these are reachable by anyone
// who can talk to the loopback port: an unbounded write into the household's
// alert banner, and ten bcrypt-12 hashes of CPU per request.

const FLOOD_WRONG_CODE = 'AAAA-BBBB-CCCC-DDDD-EEEE';

/**
 * @return list<string>
 */
function floodAccount(string $username): array
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('flood-password-12ch'),
        'period_start_day' => 1,
    ]);

    /** @var RecoveryCodeMinter $minter */
    $minter = app(RecoveryCodeMinter::class);

    return $minter->issueFor($user->id);
}

function floodOpenFailureAlerts(): int
{
    return DB::table('system_alerts')
        ->where('kind', 'auth.recovery_code_failed')
        ->whereNull('acknowledged_at')
        ->count();
}

function floodAttemptReset(string $username, string $code): void
{
    /** @var ResetPasswordAction $reset */
    $reset = app(ResetPasswordAction::class);

    try {
        $reset($username, $code, 'flood-brand-new-pw');
    } catch (ValidationException) {
        // The refusal is the point; what it costs is what is under test.
    }
}

it('leaves one open critical alert however many failed attempts arrive', function (): void {
    floodAccount('flood-alice');

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = app(RecoveryCodeAuthenticator::class);

    for ($i = 0; $i < 20; $i++) {
        $authenticator->verify('flood-alice', FLOOD_WRONG_CODE);
    }

    expect(DB::table('system_alerts')->where('kind', 'auth.recovery_code_failed')->count())->toBe(1);
});

// Deduping must not silence the second break-in attempt: once the reader has
// answered the banner, the next failure has to reach them.
it('raises a fresh alert once the open one has been acknowledged', function (): void {
    floodAccount('flood-bob');

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = app(RecoveryCodeAuthenticator::class);

    $authenticator->verify('flood-bob', FLOOD_WRONG_CODE);
    expect(floodOpenFailureAlerts())->toBe(1);

    DB::table('system_alerts')
        ->where('kind', 'auth.recovery_code_failed')
        ->update(['acknowledged_at' => now()]);

    $authenticator->verify('flood-bob', FLOOD_WRONG_CODE);

    expect(floodOpenFailureAlerts())->toBe(1);
    expect(DB::table('system_alerts')->where('kind', 'auth.recovery_code_failed')->count())->toBe(2);
});

it('stops paying the verification cost once the attempt cap is reached', function (): void {
    $codes = floodAccount('flood-carol');

    for ($i = 0; $i < 5; $i++) {
        floodAttemptReset('flood-carol', FLOOD_WRONG_CODE);
    }

    // A genuine code, refused: the sheet is untouched, which is the proof that
    // the ten-hash verification never ran at all.
    expect(fn () => app(ResetPasswordAction::class)('flood-carol', $codes[0], 'flood-brand-new-pw'))
        ->toThrow(ValidationException::class);

    expect(UserRecoveryCode::withoutGlobalScopes()->whereNull('used_at')->count())->toBe(10);
});

it('names the throttle rather than blaming the code the reader typed', function (): void {
    floodAccount('flood-dave');

    for ($i = 0; $i < 5; $i++) {
        floodAttemptReset('flood-dave', FLOOD_WRONG_CODE);
    }

    try {
        app(ResetPasswordAction::class)('flood-dave', FLOOD_WRONG_CODE, 'flood-brand-new-pw');
        expect(false)->toBeTrue('the sixth attempt was not refused');
    } catch (ValidationException $e) {
        expect($e->validator->errors()->first('code'))->toStartWith('Too many attempts');
    }
});

// A reader who mistypes twice and then gets it right must not find the meter
// still standing the next time they need a code.
it('clears the meter on a successful redemption', function (): void {
    $codes = floodAccount('flood-erin');

    floodAttemptReset('flood-erin', FLOOD_WRONG_CODE);
    floodAttemptReset('flood-erin', FLOOD_WRONG_CODE);

    app(ResetPasswordAction::class)('flood-erin', $codes[0], 'flood-brand-new-pw');

    for ($i = 0; $i < 4; $i++) {
        floodAttemptReset('flood-erin', FLOOD_WRONG_CODE);
    }

    // Still inside the cap, so this one is verified rather than refused.
    app(ResetPasswordAction::class)('flood-erin', $codes[1], 'flood-brand-new-pw-2');

    expect(UserRecoveryCode::withoutGlobalScopes()->whereNull('used_at')->count())->toBe(8);
});
