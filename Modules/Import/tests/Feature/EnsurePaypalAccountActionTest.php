<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Ledger\Models\Account;

beforeEach(function (): void {
    // The canonical fixture user already carries a PayPal accounts row, which
    // would defeat every create-on-first-call assertion below.
    $this->primaryUser = User::query()->create([
        'username' => 'paypal-action-primary',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $this->secondaryUser = User::query()->create([
        'username' => 'paypal-action-secondary',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('creates a synthetic PayPal account on first call and returns true', function (): void {
    expect(
        Account::query()
            ->where('user_id', $this->primaryUser->id)
            ->where('iban', 'PAYPAL')
            ->count()
    )->toBe(0);

    /** @var EnsurePaypalAccountAction $action */
    $action = $this->app->make(EnsurePaypalAccountAction::class);

    $created = ($action)($this->primaryUser);

    expect($created)->toBeTrue();

    /** @var Account|null $account */
    $account = Account::query()
        ->where('user_id', $this->primaryUser->id)
        ->where('iban', 'PAYPAL')
        ->first();

    expect($account)->not->toBeNull();
    /** @var Account $account */
    expect($account->kind)->toBe('paypal');
    expect($account->default_currency)->toBe('EUR');
    expect($account->name)->toBe('PayPal');
    expect($account->slug)->toBe('paypal');
});

it('does nothing and returns false when a PayPal account already exists for the user', function (): void {
    /** @var EnsurePaypalAccountAction $action */
    $action = $this->app->make(EnsurePaypalAccountAction::class);

    $firstResult = ($action)($this->primaryUser);
    expect($firstResult)->toBeTrue();

    $secondResult = ($action)($this->primaryUser);

    expect($secondResult)->toBeFalse();
    expect(
        Account::query()
            ->where('user_id', $this->primaryUser->id)
            ->where('iban', 'PAYPAL')
            ->count()
    )->toBe(1);
});

it('honors a name override and slugs it through the shared resolver', function (): void {
    /** @var EnsurePaypalAccountAction $action */
    $action = $this->app->make(EnsurePaypalAccountAction::class);

    $created = ($action)(
        $this->primaryUser,
        nameOverride: 'PayPal NL',
    );

    expect($created)->toBeTrue();

    /** @var Account|null $account */
    $account = Account::query()
        ->where('user_id', $this->primaryUser->id)
        ->where('iban', 'PAYPAL')
        ->first();

    expect($account)->not->toBeNull();
    /** @var Account $account */
    expect($account->name)->toBe('PayPal NL');
    expect($account->slug)->toBe('paypal-nl');
    expect($account->kind)->toBe('paypal');
    expect($account->iban)->toBe('PAYPAL');
    expect($account->default_currency)->toBe('EUR');
});

it('scopes the existence check by user_id so two users each get their own PayPal row', function (): void {
    /** @var EnsurePaypalAccountAction $action */
    $action = $this->app->make(EnsurePaypalAccountAction::class);

    $primaryCreated = ($action)($this->primaryUser);
    $secondaryCreated = ($action)($this->secondaryUser);

    expect($primaryCreated)->toBeTrue();
    expect($secondaryCreated)->toBeTrue();

    expect(
        Account::query()
            ->where('user_id', $this->primaryUser->id)
            ->where('iban', 'PAYPAL')
            ->count()
    )->toBe(1);

    expect(
        Account::query()
            ->where('user_id', $this->secondaryUser->id)
            ->where('iban', 'PAYPAL')
            ->count()
    )->toBe(1);

    expect(
        Account::query()
            ->where('iban', 'PAYPAL')
            ->count()
    )->toBe(2);
});

// Four account-creation paths, one slug convention. The synthetic accounts
// used to append a literal `-paypal` / `-ics-card` of their own, so the
// codebase carried two conventions and only half the paths could be reasoned
// about from the resolver.
it('walks past a slug an unrelated account of the same name already holds', function (): void {
    Account::query()->create([
        'user_id' => $this->primaryUser->id,
        'name' => 'PayPal',
        'slug' => 'paypal',
        'kind' => 'bank',
        'iban' => 'NL01BANK0000000001',
        'default_currency' => 'EUR',
    ]);

    /** @var EnsurePaypalAccountAction $action */
    $action = $this->app->make(EnsurePaypalAccountAction::class);
    expect(($action)($this->primaryUser))->toBeTrue();

    /** @var Account $account */
    $account = Account::query()
        ->where('user_id', $this->primaryUser->id)
        ->where('iban', 'PAYPAL')
        ->firstOrFail();

    expect($account->slug)->toBe('paypal-2');
});
