<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserCountry;
use Modules\Counterparties\Internal\Enums\CounterpartyMetadataKey;
use Modules\Counterparties\Internal\Enums\CounterpartySubcategory;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// The bank-fee corpus and the known-IBAN bridge both land type='bank'. One is a
// charge the bank levies; the other is an institution the reader buys THROUGH.
// The profile body printed "Bank fees by category" over both, so every PayPal
// settlement was presented to the reader as a fee its own bank had charged.

function bankFeeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/** @param array<string, mixed>|null $metadata */
function bankFeeCounterparty(User $user, string $slug, string $name, ?array $metadata): int
{
    $now = now()->toDateTimeString();

    return DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => CounterpartyType::Bank->value,
        'slug' => $slug,
        'display_name' => $name,
        'iban' => null,
        'merchant_name' => null,
        'metadata' => $metadata === null ? null : json_encode($metadata),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('names the fee panel a fee panel only on a row the fee corpus claimed', function (): void {
    $user = bankFeeUser('bank-fee-panel');
    bankFeeCounterparty($user, 'kosten-kasopname', 'Kosten kasopname', [
        CounterpartyMetadataKey::Subcategory->value => CounterpartySubcategory::Fee->value,
        'matched_keyword' => 'KOSTEN',
    ]);

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'kosten-kasopname']);

    $component->assertSee('Bank fees by category');
    $component->assertDontSee('Activity by category');
    $component->assertSee("— bank-fee counterparty doesn't generate funding chains");
});

it('does not call an institutions spending a bank fee', function (): void {
    $user = bankFeeUser('bank-institution-panel');
    bankFeeCounterparty($user, 'paypal-europe', 'PayPal (Europe) S.a r.l.', [
        'bridge_account_kind' => 'paypal',
    ]);

    $component = Livewire::actingAs($user)
        ->test(CounterpartyProfile::class, ['slug' => 'paypal-europe']);

    $component->assertSee('Activity by category');
    $component->assertDontSee('Bank fees by category');
    $component->assertDontSee('No fees recorded on this counterparty yet.');
    $component->assertSee('— no funding chains for institution counterparties', escape: false);
});

it('carries the flag the panel branches on from the row into the profile dto', function (): void {
    $user = bankFeeUser('bank-fee-dto');
    bankFeeCounterparty($user, 'kosten', 'Kosten', [
        CounterpartyMetadataKey::Subcategory->value => CounterpartySubcategory::Fee->value,
    ]);
    bankFeeCounterparty($user, 'ics', 'International Card Services', null);

    /** @var CounterpartyProfileQuery $query */
    $query = app(CounterpartyProfileQuery::class);

    expect($query->bySlug($user, 'kosten')?->isBankFee)->toBeTrue()
        ->and($query->bySlug($user, 'ics')?->isBankFee)->toBeFalse();
});

it('writes the flag on the row the bank-fee corpus resolves, so the panel has one to read', function (): void {
    $user = bankFeeUser('bank-fee-resolved');
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'bank-fee-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    app(UserCountry::class)->store((int) $user->id, 'nl');

    /** @var CounterpartyResolver $resolver */
    $resolver = app(CounterpartyResolver::class);
    $dto = $resolver->resolve(new CanonicalTransaction(
        userId: (int) $user->id,
        accountId: (int) $account->id,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-05-01'),
        bookedAt: CarbonImmutable::parse('2026-05-01 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-05-01'),
        amountMinor: -300,
        currency: 'EUR',
        settledAmountMinor: -300,
        settledCurrency: 'EUR',
        counterpartyName: null,
        counterpartyIban: null,
        counterpartyNormalized: 'kosten-kasopname',
        normalizationVersion: 1,
        description: 'KOSTEN KASOPNAME AUTOMAAT',
        categoryId: null,
        sourceFormat: 'asn_csv',
        importRunId: 1,
        sourceRowIndex: 1,
        sourceRef: 'bank-fee:1',
    ), $user);

    expect($dto?->type)->toBe(CounterpartyType::Bank->value);

    /** @var Counterparty $stored */
    $stored = Counterparty::query()->where('id', $dto?->counterpartyId)->firstOrFail();

    /** @var CounterpartyProfileQuery $query */
    $query = app(CounterpartyProfileQuery::class);

    expect($query->bySlug($user, $stored->slug)?->isBankFee)->toBeTrue();
});
