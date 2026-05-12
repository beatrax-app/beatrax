<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Public\Services\AccountNamer;
use Modules\Ledger\Models\Account;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'namer@diederik.test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('creates an account scoped to the user with the supplied name + IBAN', function (): void {
    $namer = new AccountNamer();
    $iban = 'NL01TEST1234567890';

    $accountId = $namer($iban, 'My ASN Savings', $this->user);

    expect($accountId)->toBeGreaterThan(0);

    /** @var Account $account */
    $account = Account::query()->find($accountId);
    expect($account)->not->toBeNull();
    expect($account->iban)->toBe($iban);
    expect($account->user_id)->toBe($this->user->id);
    expect($account->name)->toBe('My ASN Savings');
    expect($account->kind)->toBe('asn');
    expect($account->default_currency)->toBe('EUR');
});

it('trims whitespace from the user-supplied name', function (): void {
    $namer = new AccountNamer();

    $accountId = $namer('NL02TEST1234567890', '  Trimmed Account  ', $this->user);

    /** @var Account $account */
    $account = Account::query()->find($accountId);
    expect($account->name)->toBe('Trimmed Account');
});

it('generates a slug containing the last 4 IBAN characters for uniqueness', function (): void {
    $namer = new AccountNamer();

    $accountId = $namer('NL03TEST9876543210', 'Another Account', $this->user);

    /** @var Account $account */
    $account = Account::query()->find($accountId);
    expect($account->slug)->toContain('3210');
});
