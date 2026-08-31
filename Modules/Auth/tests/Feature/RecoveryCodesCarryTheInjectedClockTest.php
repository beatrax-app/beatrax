<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Auth\Internal\Recovery\RecoveryCodeMinter;
use Modules\Auth\Public\Actions\AddUserAction;
use Modules\Auth\Public\Actions\RegenerateRecoveryCodesAction;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->frozenNow = CarbonImmutable::parse('2026-03-04 05:06:07');
    $clock = $this->createStub(Clock::class);
    $clock->method('now')->willReturn($this->frozenNow);
    $this->app->instance(Clock::class, $clock);

    // The minter is a singleton and the first test in a process finds it
    // already resolved against the real clock, so the stub above would reach
    // everything except the object under test.
    $this->app->forgetInstance(RecoveryCodeMinter::class);
});

/**
 * @return list<string>
 */
function issuedRecoveryCodeStamps(int $userId): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var list<string> $stamps */
    $stamps = $db->connection()->table('user_recovery_codes')
        ->where('user_id', $userId)
        ->whereNull('used_at')
        ->pluck('created_at')
        ->all();

    return $stamps;
}

it('stamps the codes signup issues with the injected clock', function (): void {
    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    $result = $signup('alice', 'a-long-password-12chars');

    expect(issuedRecoveryCodeStamps($result['user']->id))
        ->toHaveCount(10)
        ->each->toBe($this->frozenNow->toDateTimeString());
});

it('stamps the codes a partner is handed at their first password change with the injected clock', function (): void {
    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);
    $owner = $signup('owner', 'a-long-password-12chars')['user'];

    /** @var AddUserAction $addUser */
    $addUser = $this->app->make(AddUserAction::class);
    $partner = $addUser($owner, 'partner', 'a-long-password-12chars');

    Livewire::actingAs($partner)->test(ChangePasswordPage::class)
        ->set('currentPassword', 'a-long-password-12chars')
        ->set('newPassword', 'a-password-of-their-own')
        ->set('newPasswordConfirmation', 'a-password-of-their-own')
        ->call('submit');

    expect(issuedRecoveryCodeStamps($partner->id))
        ->toHaveCount(10)
        ->each->toBe($this->frozenNow->toDateTimeString());
});

it('stamps a regenerated sheet with the injected clock', function (): void {
    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);
    $owner = $signup('owner', 'a-long-password-12chars')['user'];

    /** @var RegenerateRecoveryCodesAction $regenerate */
    $regenerate = $this->app->make(RegenerateRecoveryCodesAction::class);
    $regenerate($owner, 'owner');

    /** @var User $reloaded */
    $reloaded = User::query()->where('username', 'owner')->firstOrFail();

    expect(issuedRecoveryCodeStamps($reloaded->id))
        ->toHaveCount(10)
        ->each->toBe($this->frozenNow->toDateTimeString());
});

it('never issues the same code twice on one sheet', function (): void {
    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    $codes = $signup('alice', 'a-long-password-12chars')['codesPlain'];

    expect(array_values(array_unique($codes)))->toBe($codes);
});
