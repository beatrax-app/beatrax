<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Detectors\MerchantDisplayName;
use Modules\Recurring\Internal\Queries\SeriesAccountResolver;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;
use Modules\Recurring\Public\Services\RecurringOccurrenceQuery;

// observed_at and posted_at are DATE columns, so two charges of one merchant on
// one day tie, and the second sort term decided everything downstream. That term
// was an id: minted with random_int for an occurrence, counted per device for a
// transaction or an account. Every fixture here gives the EARLIER charge the
// LARGER id, so an id tie-break answers with the earlier row and the charge's own
// booked time answers with the later one.

function sdtUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function sdtAccount(User $user, string $slug, string $name, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function sdtRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sdt.csv',
        'sha256' => str_pad($sha, 64, '0'),
        'uploaded_at' => CarbonImmutable::parse('2026-05-01 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function sdtTransaction(DatabaseManager $db, int $id, User $user, Account $account, ImportRun $run, array $overrides = []): int
{
    $db->connection()->table('transactions')->insert(array_merge([
        'id' => $id,
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Tie Merchant',
        'counterparty_normalized' => 'tie merchant',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $id,
        'fingerprint' => str_pad('sdt-'.$id, 64, 'f', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'occurrence_ordinal' => 0,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ], $overrides));

    return $id;
}

function sdtSeries(User $user, string $name): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1099,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::'.$name.'::eur::monthly',
        'cluster_counterparty_key' => $name,
        'next_expected_at' => '2026-06-15',
        'next_expected_confidence_low' => false,
    ]);
}

function sdtOccurrence(DatabaseManager $db, int $id, RecurringSeries $series, int $transactionId, int $amountMinor): void
{
    $db->connection()->table('recurring_series_occurrences')->insert([
        'id' => $id,
        'user_id' => $series->user_id,
        'recurring_series_id' => $series->id,
        'transaction_id' => $transactionId,
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => $amountMinor,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}

/**
 * @return array{RecurringSeries, int, int}
 */
function sdtSameDayPair(DatabaseManager $db, User $user, Account $account): array
{
    $run = sdtRun($user, 'sdt'.$user->id);
    $earlyTx = sdtTransaction($db, 900 + (int) $user->id, $user, $account, $run, [
        'booked_at' => '2026-05-15 09:00:00',
        'amount_minor' => -1000,
        'settled_amount_minor' => -1000,
    ]);
    $lateTx = sdtTransaction($db, 10 + (int) $user->id, $user, $account, $run, [
        'booked_at' => '2026-05-15 17:00:00',
        'amount_minor' => -1500,
        'settled_amount_minor' => -1500,
    ]);

    $series = sdtSeries($user, 'tie merchant');
    sdtOccurrence($db, 9_000_000_000_000_000_000, $series, $earlyTx, -1000);
    sdtOccurrence($db, 101, $series, $lateTx, -1500);

    return [$series, $earlyTx, $lateTx];
}

beforeEach(function (): void {
    $this->db = $this->app->make(DatabaseManager::class);
    $this->user = sdtUser('sdt-reader');
    $this->account = sdtAccount($this->user, 'sdt-main', 'SDT Bank', 'NL00SDT0000000001');
});

// DriftEvaluator reads occurrence 0 as "latest" and occurrence 1 as "prior", so
// a coin-flipped pair reverses the sign of the movement it alerts on.
it('hands the drift evaluator the day s later charge, not the larger minted id', function (): void {
    [$series] = sdtSameDayPair($this->db, $this->user, $this->account);

    /** @var RecurringOccurrenceQuery $query */
    $query = $this->app->make(RecurringOccurrenceQuery::class);

    expect(array_map(
        static fn ($o): int => $o->observedAmount->toMinor(),
        $query->latestOccurrencesForSeries((int) $series->id, $this->user, 2),
    ))->toBe([-1500, -1000]);
});

it('lists a day s occurrences later charge first', function (): void {
    [$series] = sdtSameDayPair($this->db, $this->user, $this->account);

    /** @var RecurringOccurrenceQuery $query */
    $query = $this->app->make(RecurringOccurrenceQuery::class);

    expect(array_map(
        static fn ($o): int => $o->observedAmount->toMinor(),
        $query->occurrencesForSeries((int) $series->id, $this->user),
    ))->toBe([-1500, -1000]);
});

// The chart runs oldest-first, so the day's later charge is its LAST point.
it('ends the amount trend on the day s later charge', function (): void {
    [$series] = sdtSameDayPair($this->db, $this->user, $this->account);

    /** @var RecurringOccurrenceQuery $query */
    $query = $this->app->make(RecurringOccurrenceQuery::class);

    $points = $query->amountTrendForSeries((int) $series->id, $this->user)->points;

    expect($points)->toHaveCount(2);
    expect($points[1]['amount_minor'])->toBe(-1500);
});

it('resolves the series account from the day s later charge', function (): void {
    $other = sdtAccount($this->user, 'sdt-other', 'SDT Card', 'NL00SDT0000000002');
    $run = sdtRun($this->user, 'sdtacc');

    $earlyTx = sdtTransaction($this->db, 8000, $this->user, $this->account, $run, [
        'booked_at' => '2026-05-15 09:00:00',
        'amount_minor' => -1000,
        'settled_amount_minor' => -1000,
    ]);
    $lateTx = sdtTransaction($this->db, 20, $this->user, $other, $run, [
        'booked_at' => '2026-05-15 17:00:00',
        'amount_minor' => -1500,
        'settled_amount_minor' => -1500,
    ]);

    $series = sdtSeries($this->user, 'tie merchant');
    sdtOccurrence($this->db, 8_100_000_000_000_000_000, $series, $earlyTx, -1000);
    sdtOccurrence($this->db, 201, $series, $lateTx, -1500);

    /** @var SeriesAccountResolver $resolver */
    $resolver = $this->app->make(SeriesAccountResolver::class);

    expect($resolver->forSeriesIds([(int) $series->id], $this->user))
        ->toBe([(int) $series->id => (int) $other->id]);
});

// A second current account at one bank, or a second PayPal, is the everyday
// shape of this tie: the two rows carry the same name and different IBANs.
it('falls back to the account the iban orders first when two share a name', function (): void {
    $user = sdtUser('sdt-fallback');
    $lastIban = sdtAccount($user, 'sdt-zz', 'ASN Bank', 'NL99ASNB0000000009');
    $firstIban = sdtAccount($user, 'sdt-aa', 'ASN Bank', 'NL01ASNB0000000001');

    expect((int) $lastIban->id)->toBeLessThan((int) $firstIban->id);

    $series = sdtSeries($user, 'no occurrences yet');

    /** @var SeriesAccountResolver $resolver */
    $resolver = $this->app->make(SeriesAccountResolver::class);

    expect($resolver->forSeriesIds([(int) $series->id], $user))
        ->toBe([(int) $series->id => (int) $firstIban->id]);
});

// Two devices labelling one series differently is the visible half of this
// shape: the name is read off whichever charge the sort put first.
it('labels the series with the day s later merchant string', function (): void {
    $run = sdtRun($this->user, 'sdtname');

    sdtTransaction($this->db, 7000, $this->user, $this->account, $run, [
        'booked_at' => '2026-05-15 09:00:00',
        'amount_minor' => -1000,
        'settled_amount_minor' => -1000,
        'counterparty_name' => 'ALBERT HEIJN 1234',
    ]);
    sdtTransaction($this->db, 30, $this->user, $this->account, $run, [
        'booked_at' => '2026-05-15 17:00:00',
        'amount_minor' => -1500,
        'settled_amount_minor' => -1500,
        'counterparty_name' => 'Albert Heijn',
    ]);

    /** @var MerchantDisplayName $names */
    $names = $this->app->make(MerchantDisplayName::class);

    expect($names->forNormalized((int) $this->user->id, 'tie merchant'))->toBe('Albert Heijn');
});

it('walks back to the chain link of the day s later charge', function (): void {
    [$series, $earlyTx, $lateTx] = sdtSameDayPair($this->db, $this->user, $this->account);
    $series->forceFill(['latest_funding_chain_link_id' => null])->save();

    $run = sdtRun($this->user, 'sdtchain');
    $earlyTo = sdtTransaction($this->db, 4001, $this->user, $this->account, $run, [
        'type' => 'transfer_out',
        'occurrence_ordinal' => 1,
    ]);
    $lateTo = sdtTransaction($this->db, 4002, $this->user, $this->account, $run, [
        'type' => 'transfer_out',
        'occurrence_ordinal' => 2,
    ]);

    $earlyLink = sdtChainLink($this->db, $this->user, $earlyTx, $earlyTo);
    $lateLink = sdtChainLink($this->db, $this->user, $lateTx, $lateTo);

    expect($earlyLink)->not->toBe($lateLink);

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);
    $sections = $query->viewForUser($this->user);

    expect($sections['expenses'])->toHaveCount(1);
    expect($sections['expenses'][0]->latestFundingChainLinkId)->toBe($lateLink);
});

function sdtChainLink(DatabaseManager $db, User $user, int $fromTransactionId, int $toTransactionId): int
{
    return (int) $db->connection()->table('chain_links')->insertGetId([
        'user_id' => $user->id,
        'from_transaction_id' => $fromTransactionId,
        'to_transaction_id' => $toTransactionId,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => 1.000,
        'resolver' => 'auto',
        'evidence' => json_encode(['kind' => 'paypal_funding']),
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}
