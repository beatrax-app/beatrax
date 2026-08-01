<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Models\Account;

uses(RefreshDatabase::class);

it('persists an account with required fields', function (): void {
    $account = Account::create([
        'name' => 'ASN spaarrekening',
        'slug' => 'asn-spaar',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    expect($account->id)->toBeInt();
    expect($account->name)->toBe('ASN spaarrekening');
    expect($account->default_currency)->toBe('EUR');
    expect($account->kind)->toBe('bank');
});

it('accepts a nullable user_id for multi-user readiness', function (): void {
    $a = Account::create([
        'name' => 'No user',
        'slug' => 'no-user',
        'kind' => 'bank',
        'iban' => 'NL08ASNB9999999999',
        'default_currency' => 'EUR',
    ]);

    expect($a->user_id)->toBeNull();
});
