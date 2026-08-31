<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\Http\Livewire\ChainsIndex;
use Modules\Chains\Internal\Presentation\SettlementGroup;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// /chains read a flat page of links and grouped whatever came back. A settled
// ICS statement covers 50 to 300 charges, so the cut fell inside a settlement,
// and the card stated a count and a total for the slice it happened to load
// under a heading carrying the settlement's whole amount.

function fedCardUser(): User
{
    return User::query()->create([
        'username' => 'fed-card-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function fedCardAccount(User $user, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'fed '.$kind,
        'slug' => 'fed-'.$kind.'-'.bin2hex(random_bytes(3)),
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function fedCardTx(User $user, Account $account, ImportRun $run, int $amountMinor, string $type, string $name, string $postedAt, int $rowIndex, string $currency = 'EUR'): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_name' => $name,
        'counterparty_normalized' => strtolower($name).'-'.$rowIndex,
        'normalization_version' => 3,
        'source_format' => 'ics-pdf',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => hash('sha256', 'fed-card-'.$user->id.'-'.$rowIndex),
        'fingerprint_version' => 3,
    ]);
}

function fedCardLink(DatabaseManager $db, User $user, int $fromId, int $toId, string $kind, string $state = 'confirmed'): void
{
    $db->connection()->table('chain_links')->insert([
        'id' => ChainLinkInsertHelper::idFor($user->id, $fromId, $toId, $kind),
        'user_id' => $user->id,
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => $kind,
        'state' => $state,
        'confidence' => '1.000',
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => 'fed-sig']),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = fedCardUser();
    $this->actingAs($this->user);
    $this->bank = fedCardAccount($this->user, 'bank', 'NL57ASNB0123456789');
    $this->ics = fedCardAccount($this->user, 'ics_card', 'ICS-FED');
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/fed.pdf',
        'sha256' => hash('sha256', 'fed-run-'.$this->user->id),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var ChainLinkQuery $query */
    $query = $this->app->make(ChainLinkQuery::class);
    $this->query = $query;
});

/**
 * @return array{0: Transaction, 1: list<Transaction>}
 */
function fedCardSettlement(object $ctx, int $chargeCount, int $chargeMinor, string $postedAt, int $seed): array
{
    $settlement = fedCardTx($ctx->user, $ctx->bank, $ctx->run, -$chargeMinor * $chargeCount, 'transfer_out', 'ICS Cards', $postedAt, $seed);
    $charges = [];
    for ($i = 1; $i <= $chargeCount; $i++) {
        $charge = fedCardTx($ctx->user, $ctx->ics, $ctx->run, -$chargeMinor, 'expense', 'Shop', '2026-05-10', $seed + $i);
        fedCardLink($ctx->db, $ctx->user, (int) $settlement->id, (int) $charge->id, 'ics_bulk_settle');
        $charges[] = $charge;
    }

    return [$settlement, $charges];
}

it('states the payment count of the whole settlement, not of the slice it loaded', function (): void {
    [$settlement] = fedCardSettlement($this, 120, 1000, '2026-05-20', 1000);

    $groups = SettlementGroup::fromRows(
        $this->query->allChainsForUser($this->user),
        $this->query->settlementTotalsForUser($this->user),
    );

    expect($groups)->toHaveCount(1);
    expect($groups[0]->transactionId)->toBe((int) $settlement->id);
    expect($groups[0]->legCount)->toBe(120);
    expect($groups[0]->legTotals)->toHaveCount(1);
    expect($groups[0]->legTotals[0]->toMinor())->toBe(-120000);
});

it('renders a leg total the settlement heading above it agrees with', function (): void {
    fedCardSettlement($this, 120, 1000, '2026-05-20', 2000);

    $html = Livewire::actingAs($this->user)->test(ChainsIndex::class)->html();

    // The heading amount and the leg total are the same figure, 120 x EUR
    // 10.00, so the card agrees with itself rather than with its own slice.
    expect($html)->toContain('120 payments');
    expect(substr_count($html, '€1,200.00'))->toBeGreaterThanOrEqual(2);
});

// A candidate leg past the display cap still needs a person to look at it, so
// the badge is read off every leg rather than off the ones that fit.
it('badges the settlement candidate when the unreviewed leg is past the display cap', function (): void {
    [$settlement] = fedCardSettlement($this, 40, 1000, '2026-05-20', 3000);
    $late = fedCardTx($this->user, $this->ics, $this->run, -1000, 'expense', 'Late shop', '2026-05-10', 3999);
    fedCardLink($this->db, $this->user, (int) $settlement->id, (int) $late->id, 'ics_bulk_settle', 'candidate');

    $groups = SettlementGroup::fromRows(
        $this->query->allChainsForUser($this->user),
        $this->query->settlementTotalsForUser($this->user),
    );

    expect($groups)->toHaveCount(1);
    expect($groups[0]->legCount)->toBe(41);
    expect($groups[0]->state)->toBe('candidate');
});

// A settlement whose legs are not all in one currency has no single total, and
// each currency's own figure must count every leg denominated in it.
it('states one true total per currency', function (): void {
    $settlement = fedCardTx($this->user, $this->bank, $this->run, -30000, 'transfer_out', 'ICS Cards', '2026-05-20', 4000);
    for ($i = 1; $i <= 20; $i++) {
        $charge = fedCardTx($this->user, $this->ics, $this->run, -1000, 'expense', 'Shop', '2026-05-10', 4000 + $i);
        fedCardLink($this->db, $this->user, (int) $settlement->id, (int) $charge->id, 'ics_bulk_settle');
    }
    for ($i = 1; $i <= 20; $i++) {
        $charge = fedCardTx($this->user, $this->ics, $this->run, -500, 'expense', 'Shop', '2026-05-10', 4500 + $i, 'USD');
        fedCardLink($this->db, $this->user, (int) $settlement->id, (int) $charge->id, 'ics_bulk_settle');
    }

    $groups = SettlementGroup::fromRows(
        $this->query->allChainsForUser($this->user),
        $this->query->settlementTotalsForUser($this->user),
    );

    expect($groups)->toHaveCount(1);
    expect($groups[0]->legCount)->toBe(40);
    $byCurrency = [];
    foreach ($groups[0]->legTotals as $total) {
        $byCurrency[$total->currency()] = $total->toMinor();
    }
    expect($byCurrency)->toBe(['EUR' => -20000, 'USD' => -10000]);
});

// The page reads one settlement's legs at a time; it must not spend a query per
// leg on the counterparty either side of it.
it('reads a settlement page without a query per leg', function (): void {
    fedCardSettlement($this, 30, 1000, '2026-05-20', 5000);

    $queries = 0;
    $this->db->connection()->listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::actingAs($this->user)->test(ChainsIndex::class)->html();

    expect($queries)->toBeLessThan(20);
});
