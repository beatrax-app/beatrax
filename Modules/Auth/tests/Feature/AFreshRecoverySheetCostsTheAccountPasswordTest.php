<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Recovery\RecoveryCodeMinter;
use Modules\Auth\Public\Http\Livewire\RecoveryCodesSection;
use Modules\Core\Models\User;

// A sheet minted here outlives the session that asked for it, and the password
// change that ends a session does not retire it. So regeneration with nothing
// asked turned borrowed session access into permanent entry, which is the one
// thing the recovery sheet is not allowed to become.

const RECOVERY_REGENERATION_PASSWORD = 'recovery-regen-pass-12';

function recoveryRegenerationUser(): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'regen-reader',
        'password' => RECOVERY_REGENERATION_PASSWORD,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    return $user;
}

/**
 * @return list<string> the code hashes standing unused for this account
 */
function recoveryRegenerationStandingHashes(DatabaseManager $db, int $userId): array
{
    return $db->connection()
        ->table('user_recovery_codes')
        ->where('user_id', $userId)
        ->whereNull('used_at')
        ->orderBy('code_hash')
        ->pluck('code_hash')
        ->map(static fn (mixed $hash): string => (string) $hash)
        ->values()
        ->all();
}

it('mints nothing and leaves the standing sheet alive where no account password is typed', function (): void {
    $user = recoveryRegenerationUser();
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->app->make(RecoveryCodeMinter::class)->issueFor($user->id);

    $before = recoveryRegenerationStandingHashes($db, $user->id);

    $component = Livewire::test(RecoveryCodesSection::class)->call('regenerate');

    expect(recoveryRegenerationStandingHashes($db, $user->id))->toBe(
        $before,
        'a caller who typed nothing retired the sheet the reader holds and minted one it keeps',
    );

    expect(session()->has(RecoveryCodesDisplay::SESSION_KEY))->toBeFalse(
        'the plaintext sheet was handed to a caller that proved nothing',
    );

    $component->assertNoRedirect();
});

it('mints nothing where the typed password is not this account\'s', function (): void {
    $user = recoveryRegenerationUser();
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->app->make(RecoveryCodeMinter::class)->issueFor($user->id);

    $before = recoveryRegenerationStandingHashes($db, $user->id);

    Livewire::test(RecoveryCodesSection::class)
        ->set('accountPassword', 'not-the-account-password')
        ->call('regenerate')
        ->assertNoRedirect();

    expect(recoveryRegenerationStandingHashes($db, $user->id))->toBe($before);
    expect(session()->has(RecoveryCodesDisplay::SESSION_KEY))->toBeFalse();
});

it('mints a fresh sheet, and retires the standing one, once the account password is right', function (): void {
    $user = recoveryRegenerationUser();
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->app->make(RecoveryCodeMinter::class)->issueFor($user->id);

    $before = recoveryRegenerationStandingHashes($db, $user->id);
    expect($before)->toHaveCount(10);

    $component = Livewire::test(RecoveryCodesSection::class)
        ->set('accountPassword', RECOVERY_REGENERATION_PASSWORD)
        ->call('regenerate')
        ->assertRedirect(route('auth.recovery-codes-display'));

    $after = recoveryRegenerationStandingHashes($db, $user->id);

    expect($after)->toHaveCount(10)
        ->and(array_intersect($after, $before))->toBe([]);

    expect(session()->get(RecoveryCodesDisplay::SESSION_KEY))->toHaveCount(10);

    // Kept off the wire the moment it is spent, the way every other account
    // password box on this screen and the next one clears itself.
    expect($component->get('accountPassword'))->toBe('');
});
