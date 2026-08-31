<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Internal\Enums\ChainLinkResolver;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Chains\Public\Dto\SeriesFunderLink;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

function reviewSurfaceAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'surface '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function reviewSurfaceTx(User $user, Account $account, ImportRun $run, array $overrides = []): Transaction
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
        'amount_minor' => -1200,
        'currency' => 'EUR',
        'settled_amount_minor' => -1200,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Surface merchant',
        'counterparty_normalized' => 'surface-'.$row,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => hash('sha256', 'review-surface-'.$row),
        'fingerprint_version' => 3,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function reviewSurfaceLink(DatabaseManager $db, User $user, ?int $fromId, ?int $toId, array $overrides = []): int
{
    $db->connection()->table('chain_links')->insert(array_merge([
        'user_id' => $user->id,
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => 'paypal_funding',
        'state' => 'candidate',
        'confidence' => '0.800',
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => 'surface-sig']),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ], $overrides));

    return (int) $db->connection()->table('chain_links')->max('id');
}

/**
 * A one-occurrence approved series whose occurrence transaction carries one
 * candidate funder link, for the tests that then move that link's state.
 *
 * @return array{seriesId: int, fundedId: int, funderId: int, linkId: int}
 */
function reviewSurfaceSeriesWithFunder(DatabaseManager $db, User $user, Account $spender, Account $funderAccount, ImportRun $run, int $amountMinor, string $key): array
{
    $funded = reviewSurfaceTx($user, $spender, $run, [
        'amount_minor' => $amountMinor,
        'settled_amount_minor' => $amountMinor,
    ]);
    $funder = reviewSurfaceTx($user, $funderAccount, $run, [
        'type' => 'transfer_out',
        'amount_minor' => $amountMinor,
        'settled_amount_minor' => $amountMinor,
    ]);

    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()->create([
        'user_id' => $user->id,
        'cadence' => 'monthly',
        'direction' => 'expense',
        'detected_name' => 'Surface series '.$key,
        'state' => 'approved',
        'variance_tolerance_percent' => 5,
        'latest_amount_minor' => $amountMinor,
        'latest_currency' => 'EUR',
        'cluster_key' => 'surface-series-'.$key,
        'next_expected_at' => '2026-05-15',
    ]);
    RecurringSeriesOccurrence::query()->create([
        'user_id' => $user->id,
        'recurring_series_id' => $series->id,
        'transaction_id' => $funded->id,
        'observed_at' => '2026-04-15',
        'observed_amount_minor' => $amountMinor,
        'observed_currency' => 'EUR',
    ]);

    return [
        'seriesId' => (int) $series->id,
        'fundedId' => (int) $funded->id,
        'funderId' => (int) $funder->id,
        'linkId' => reviewSurfaceLink($db, $user, (int) $funded->id, (int) $funder->id),
    ];
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'review-surfaces',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->asn = reviewSurfaceAccount($this->user, 'surface-asn', 'bank', 'NL57ASNB0123456789');
    $this->paypal = reviewSurfaceAccount($this->user, 'surface-paypal', 'paypal', 'PAYPAL');

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/review-surfaces.csv',
        'sha256' => str_repeat('s', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var ChainLinkQuery $query */
    $query = $this->app->make(ChainLinkQuery::class);
    $this->query = $query;
});

// The sidebar badge counted every candidate; /chains/review refuses to show the
// NULL-endpoint ones, so a reader following a badge of 1 arrived at an empty
// page. hintCount() is the badge those rows belong to.
it('counts for the sidebar exactly what the review queue can show', function (): void {
    $hintSource = reviewSurfaceTx($this->user, $this->asn, $this->run, ['type' => 'transfer_out']);
    reviewSurfaceLink($this->db, $this->user, (int) $hintSource->id, null, [
        'kind' => 'ics_bulk_settle',
        'evidence' => json_encode(['tolerance_used' => 'exceeded', 'signature_hash' => 'surface-hint']),
    ]);

    expect($this->query->openCandidateCount($this->user))->toBe(0);
    expect($this->query->candidatesForReview($this->user))->toBeEmpty();
    expect($this->query->hintCount($this->user))->toBe(1);

    $from = reviewSurfaceTx($this->user, $this->paypal, $this->run);
    $to = reviewSurfaceTx($this->user, $this->asn, $this->run, ['type' => 'transfer_in', 'amount_minor' => 1200, 'settled_amount_minor' => 1200]);
    reviewSurfaceLink($this->db, $this->user, (int) $from->id, (int) $to->id);

    expect($this->query->openCandidateCount($this->user))->toBe(1);
});

// The queue read 26 rows, rendered all 26, and offered Show more at 25 — so a
// reader with exactly 25 candidates got a button whose next page was empty.
it('offers Show more only when there is a next page to show', function (): void {
    $funders = [];
    for ($i = 0; $i < 25; $i++) {
        $from = reviewSurfaceTx($this->user, $this->paypal, $this->run);
        $to = reviewSurfaceTx($this->user, $this->asn, $this->run, ['type' => 'transfer_in', 'amount_minor' => 1200, 'settled_amount_minor' => 1200]);
        $funders[] = reviewSurfaceLink($this->db, $this->user, (int) $from->id, (int) $to->id);
    }

    Livewire::test(ChainReviewQueue::class)
        ->assertViewHas('hasMore', false)
        ->assertDontSee('chains::review.show_more');

    $from = reviewSurfaceTx($this->user, $this->paypal, $this->run);
    $to = reviewSurfaceTx($this->user, $this->asn, $this->run, ['type' => 'transfer_in', 'amount_minor' => 1200, 'settled_amount_minor' => 1200]);
    reviewSurfaceLink($this->db, $this->user, (int) $from->id, (int) $to->id);

    $component = Livewire::test(ChainReviewQueue::class);
    $component->assertViewHas('hasMore', true);
    $component->assertViewHas('candidates', fn (array $candidates): bool => count($candidates) === 25);
});

// state is the whole test. Anding a resolver = 'auto' predicate onto it kept
// rejected links out only by accident and kept the learning loop's own
// confirmations out on purpose, which was the defect.
it('routes a series onto a funder the reader confirmed, never onto one they rejected', function (): void {
    $funded = reviewSurfaceTx($this->user, $this->paypal, $this->run, ['amount_minor' => -12000, 'settled_amount_minor' => -12000]);
    $funder = reviewSurfaceTx($this->user, $this->asn, $this->run, [
        'type' => 'transfer_out',
        'amount_minor' => -12000,
        'settled_amount_minor' => -12000,
    ]);

    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'cadence' => 'monthly',
        'direction' => 'expense',
        'detected_name' => 'Surface series',
        'state' => 'approved',
        'variance_tolerance_percent' => 5,
        'latest_amount_minor' => -12000,
        'latest_currency' => 'EUR',
        'cluster_key' => 'surface-series',
        'next_expected_at' => '2026-05-15',
    ]);
    RecurringSeriesOccurrence::query()->create([
        'user_id' => $this->user->id,
        'recurring_series_id' => $series->id,
        'transaction_id' => $funded->id,
        'observed_at' => '2026-04-15',
        'observed_amount_minor' => -12000,
        'observed_currency' => 'EUR',
    ]);

    $linkId = reviewSurfaceLink($this->db, $this->user, (int) $funded->id, (int) $funder->id, ['state' => 'rejected']);

    expect($this->query->confirmedFundersForSeries((int) $series->id, $this->user))->toBeEmpty();

    $this->db->connection()->table('chain_links')->where('id', $linkId)->update(['state' => 'confirmed']);

    $routed = $this->query->confirmedFundersForSeries((int) $series->id, $this->user);
    expect($routed)->toHaveCount(1);
    expect($routed[0]->toTransactionId)->toBe((int) $funder->id);
});

it('routes a series onto the funder the learning loop confirmed for it', function (): void {
    $fixture = reviewSurfaceSeriesWithFunder($this->db, $this->user, $this->paypal, $this->asn, $this->run, -13000, 'loop');

    $this->db->connection()->table('chain_links')
        ->where('id', $fixture['linkId'])
        ->update(['state' => 'confirmed', 'resolver' => ChainLinkResolver::Rule->value]);

    $routed = $this->query->confirmedFundersForSeries($fixture['seriesId'], $this->user);

    expect($routed)->toHaveCount(1);
    expect($routed[0]->toTransactionId)->toBe($fixture['funderId']);
});

it('names the same funder canonical on every read when a series has two', function (): void {
    $fixture = reviewSurfaceSeriesWithFunder($this->db, $this->user, $this->paypal, $this->asn, $this->run, -14000, 'two');

    $this->db->connection()->table('chain_links')
        ->where('id', $fixture['linkId'])
        ->update(['state' => 'confirmed', 'confidence' => '0.700']);

    $stronger = reviewSurfaceTx($this->user, $this->asn, $this->run, [
        'type' => 'transfer_out',
        'amount_minor' => -14000,
        'settled_amount_minor' => -14000,
    ]);
    reviewSurfaceLink($this->db, $this->user, $fixture['fundedId'], (int) $stronger->id, [
        'state' => 'confirmed',
        'confidence' => '1.000',
    ]);

    // The router reads links[0] as the funder, so an unordered query let two
    // equally eligible rows take turns and moved the projection between
    // accounts for no reason the reader could see.
    $first = $this->query->confirmedFundersForSeries($fixture['seriesId'], $this->user);
    expect($first)->toHaveCount(2);
    expect($first[0]->toTransactionId)->toBe((int) $stronger->id);
    expect(array_map(
        static fn (SeriesFunderLink $link): int => $link->toTransactionId,
        $this->query->confirmedFundersForSeries($fixture['seriesId'], $this->user),
    ))->toBe(array_map(
        static fn (SeriesFunderLink $link): int => $link->toTransactionId,
        $first,
    ));
});
