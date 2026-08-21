<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Pots\Models\Pot;

uses(RefreshDatabase::class);

it('creates and retrieves a pot via the factory', function (): void {
    $user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $pot = Pot::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'name' => 'Holiday fund',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    expect($pot->name)->toBe('Holiday fund');
    expect($pot->currency)->toBe('EUR');
    expect($pot->status)->toBe('active');
    expect($pot->account_id)->toBe($account->id);
    expect($pot->user_id)->toBe($user->id);
});

it('hides another users pots via the BelongsToUser global scope', function (): void {
    $alice = User::create([
        'username' => 'alice',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $bob = User::create([
        'username' => 'bob',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $aliceAccount = Account::create([
        'user_id' => $alice->id,
        'name' => 'Alice ASN',
        'slug' => 'alice-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000001',
        'default_currency' => 'EUR',
    ]);
    $bobAccount = Account::create([
        'user_id' => $bob->id,
        'name' => 'Bob ASN',
        'slug' => 'bob-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000002',
        'default_currency' => 'EUR',
    ]);

    Pot::factory()->create(['user_id' => $alice->id, 'account_id' => $aliceAccount->id, 'name' => 'Alices pot']);
    Pot::factory()->create(['user_id' => $bob->id, 'account_id' => $bobAccount->id, 'name' => 'Bobs pot']);

    $this->actingAs($alice);

    $visible = Pot::query()->get();
    expect($visible)->toHaveCount(1);
    expect($visible->first()->name)->toBe('Alices pot');
});

it('stores nullable user_id when no user is set', function (): void {
    $user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $pot = Pot::factory()->create([
        'user_id' => null,
        'account_id' => $account->id,
        'name' => 'Unlinked pot',
    ]);

    $this->assertDatabaseHas('pots', [
        'id' => $pot->id,
        'user_id' => null,
        'name' => 'Unlinked pot',
    ]);
});
