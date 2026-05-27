<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Ledger\Models\Account;

/**
 * Feature coverage for the synthetic PayPal account auto-create action.
 *
 * The action is idempotent: it INSERTs exactly one accounts row per user
 * (keyed on iban='PAYPAL'), returning true on the first call and false on
 * every subsequent call. PreviewWizard and the upcoming connect-paypal
 * wizard step both invoke this action so the synthetic-IBAN + kind + EUR
 * literals live in exactly one place.
 */
beforeEach(function (): void {
    // Fresh users per test — do not lean on the canonical fixture user,
    // which already seeds a PayPal accounts row in tests/TestCase.php and
    // would defeat the create-on-first-call assertion.
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
    expect($account->slug)->toBe('paypal-paypal');
});

it('does nothing and returns false when a PayPal account already exists for the user', function (): void {
    /** @var EnsurePaypalAccountAction $action */
    $action = $this->app->make(EnsurePaypalAccountAction::class);

    // First call lands the row.
    $firstResult = ($action)($this->primaryUser);
    expect($firstResult)->toBeTrue();

    // Second call must be a no-op.
    $secondResult = ($action)($this->primaryUser);

    expect($secondResult)->toBeFalse();
    expect(
        Account::query()
            ->where('user_id', $this->primaryUser->id)
            ->where('iban', 'PAYPAL')
            ->count()
    )->toBe(1);
});

it('honors name and slug-body overrides when supplied', function (): void {
    /** @var EnsurePaypalAccountAction $action */
    $action = $this->app->make(EnsurePaypalAccountAction::class);

    $created = ($action)(
        $this->primaryUser,
        nameOverride: 'PayPal NL',
        slugBodyOverride: 'paypal-nl',
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
    expect($account->slug)->toBe('paypal-nl-paypal');
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

    // Total across both users — never coalesced into a single shared row.
    expect(
        Account::query()
            ->where('iban', 'PAYPAL')
            ->count()
    )->toBe(2);
});
