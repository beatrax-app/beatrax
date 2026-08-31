<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Internal\Http\Livewire\ChainsIndex;
use Modules\Chains\Internal\Presentation\SettlementGroup;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// The grouping key was to_transaction_id for every kind. For ics_bulk_settle the
// FROM side is the settlement and the TO side is the charge, so one EUR 420
// payment over four charges rendered four cards, each claiming the whole 420 —
// the exact failure the class docblock says it prevents.

function settlementCardUser(): User
{
    return User::query()->create([
        'username' => 'settlement-cards',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function settlementCardAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'card '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function settlementCardTx(User $user, Account $account, ImportRun $run, array $overrides): Transaction
{
    static $row = 0;
    $row++;

    return Transaction::query()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-10',
        'booked_at' => '2026-04-10 12:00:00',
        'value_date' => '2026-04-10',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Card merchant',
        'counterparty_normalized' => 'card-merchant-'.$row,
        'normalization_version' => 3,
        'source_format' => 'ics-pdf',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => hash('sha256', 'settlement-card-'.$row),
        'fingerprint_version' => 3,
    ], $overrides));
}

function settlementCardLink(DatabaseManager $db, User $user, int $fromId, int $toId, string $kind): void
{
    $db->connection()->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => $kind,
        'state' => 'confirmed',
        'confidence' => '1.000',
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => 'card-sig']),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = settlementCardUser();
    $this->actingAs($this->user);

    $this->ics = settlementCardAccount($this->user, 'card-ics', 'ics_card', 'ICS-CARD');
    $this->asn = settlementCardAccount($this->user, 'card-asn', 'bank', 'NL57ASNB0123456789');
    $this->paypal = settlementCardAccount($this->user, 'card-paypal', 'paypal', 'PAYPAL');

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/settlement-cards.pdf',
        'sha256' => str_repeat('k', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var ChainLinkQuery $query */
    $query = $this->app->make(ChainLinkQuery::class);
    $this->query = $query;
});

it('renders one card for a bulk settlement, not one per charge it settled', function (): void {
    $settlement = settlementCardTx($this->user, $this->asn, $this->run, [
        'type' => 'transfer_out',
        'amount_minor' => -42000,
        'settled_amount_minor' => -42000,
        'counterparty_name' => 'ICS collection',
        'posted_at' => '2026-04-28',
        'booked_at' => '2026-04-28 12:00:00',
    ]);

    $charges = [];
    for ($i = 0; $i < 4; $i++) {
        $charges[] = settlementCardTx($this->user, $this->ics, $this->run, [
            'amount_minor' => -10500,
            'settled_amount_minor' => -10500,
        ]);
    }
    foreach ($charges as $charge) {
        settlementCardLink($this->db, $this->user, (int) $settlement->id, (int) $charge->id, 'ics_bulk_settle');
    }

    $groups = SettlementGroup::fromRows(
        $this->query->allChainsForUser($this->user),
        $this->query->settlementTotalsForUser($this->user),
    );

    expect($groups)->toHaveCount(1);
    expect($groups[0]->transactionId)->toBe((int) $settlement->id);
    expect($groups[0]->legs)->toHaveCount(4);
    expect($groups[0]->amount->toMinor())->toBe(-42000);
    expect($groups[0]->legTotals)->toHaveCount(1);
    expect($groups[0]->legTotals[0]->toMinor())->toBe(-42000);
});

it('still groups a PayPal chain on the funding leg the payments fan into', function (): void {
    $funder = settlementCardTx($this->user, $this->asn, $this->run, [
        'type' => 'transfer_in',
        'amount_minor' => 10000,
        'settled_amount_minor' => 10000,
        'counterparty_name' => 'Top-up from ASN',
    ]);
    $first = settlementCardTx($this->user, $this->paypal, $this->run, ['amount_minor' => -4000, 'settled_amount_minor' => -4000]);
    $second = settlementCardTx($this->user, $this->paypal, $this->run, ['amount_minor' => -6000, 'settled_amount_minor' => -6000]);

    settlementCardLink($this->db, $this->user, (int) $first->id, (int) $funder->id, 'paypal_funding');
    settlementCardLink($this->db, $this->user, (int) $second->id, (int) $funder->id, 'paypal_funding');

    $groups = SettlementGroup::fromRows(
        $this->query->allChainsForUser($this->user),
        $this->query->settlementTotalsForUser($this->user),
    );

    expect($groups)->toHaveCount(1);
    expect($groups[0]->transactionId)->toBe((int) $funder->id);
    expect($groups[0]->legs)->toHaveCount(2);
});

// Money::plus() throws CurrencyMismatchException, so one foreign leg took the
// whole /chains page down with a 500 rather than rendering the card.
it('renders a settlement whose legs are not all in one currency', function (): void {
    $funder = settlementCardTx($this->user, $this->asn, $this->run, [
        'type' => 'transfer_in',
        'amount_minor' => 10000,
        'settled_amount_minor' => 10000,
        'counterparty_name' => 'Top-up from ASN',
    ]);
    $euro = settlementCardTx($this->user, $this->paypal, $this->run, ['amount_minor' => -4000, 'settled_amount_minor' => -4000]);
    $dollar = settlementCardTx($this->user, $this->paypal, $this->run, [
        'amount_minor' => -3000,
        'settled_amount_minor' => -3000,
        'currency' => 'USD',
        'settled_currency' => 'USD',
    ]);

    settlementCardLink($this->db, $this->user, (int) $euro->id, (int) $funder->id, 'paypal_funding');
    settlementCardLink($this->db, $this->user, (int) $dollar->id, (int) $funder->id, 'paypal_funding');

    $groups = SettlementGroup::fromRows(
        $this->query->allChainsForUser($this->user),
        $this->query->settlementTotalsForUser($this->user),
    );

    expect($groups)->toHaveCount(1);
    expect($groups[0]->legTotals)->toHaveCount(2);

    $this->get('/chains')->assertOk();
});

// ucfirst() on the stored state put an English word on the badge while the
// aria-label wrapping it was localised, so the two disagreed everywhere but
// English — and the aria-label interpolated the raw token besides.
it('names the settlement state in the reader s language', function (): void {
    $settlement = settlementCardTx($this->user, $this->asn, $this->run, [
        'type' => 'transfer_out',
        'amount_minor' => -21000,
        'settled_amount_minor' => -21000,
        'counterparty_name' => 'ICS collection',
        'posted_at' => '2026-04-28',
        'booked_at' => '2026-04-28 12:00:00',
    ]);
    $charge = settlementCardTx($this->user, $this->ics, $this->run, [
        'amount_minor' => -21000,
        'settled_amount_minor' => -21000,
    ]);
    settlementCardLink($this->db, $this->user, (int) $settlement->id, (int) $charge->id, 'ics_bulk_settle');

    app()->setLocale(Locale::Nl->value);

    Livewire::actingAs($this->user)
        ->test(ChainsIndex::class)
        ->assertSee('Bevestigd')
        ->assertDontSee('Confirmed')
        ->assertDontSee('confirmed');

    app()->setLocale('en');
});
