<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Services\AccountBalanceQuery;

uses(RefreshDatabase::class);

// clearedBalance sums amount_minor, not settled_amount_minor, mirroring
// currentBalance's column choice and its single-currency assumption.

it('sums only cleared and reconciled rows, excluding uncleared', function (): void {
    $user = User::create(['username' => 'balance-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn-balance-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000001',
        'default_currency' => 'EUR',
    ]);
    $run = $this->makeImportRun($user);

    $this->makeTransaction($user, $account, $run, ['amount_minor' => -1000, 'status' => 'cleared']);
    $this->makeTransaction($user, $account, $run, ['amount_minor' => -2000, 'status' => 'reconciled']);
    $this->makeTransaction($user, $account, $run, ['amount_minor' => -5000, 'status' => 'uncleared']);

    $result = app(AccountBalanceQuery::class)->clearedBalance($account->id, $user);

    expect($result)->toBe(-3000);
});

it('scopes by user_id — a foreign user resolving another user\'s account gets 0', function (): void {
    $userA = User::create(['username' => 'balance-fixture-a', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $userB = User::create(['username' => 'balance-fixture-b', 'password' => 'fixture-password', 'period_start_day' => 1]);

    $accountA = Account::create([
        'user_id' => $userA->id,
        'name' => 'ASN A',
        'slug' => 'asn-balance-fixture-a',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000002',
        'default_currency' => 'EUR',
    ]);
    $run = $this->makeImportRun($userA);
    $this->makeTransaction($userA, $accountA, $run, ['amount_minor' => -1000, 'status' => 'cleared']);

    $result = app(AccountBalanceQuery::class)->clearedBalance($accountA->id, $userB);

    expect($result)->toBe(0);
});
