<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;
use Modules\Recurring\Models\RecurringSeriesTransition;

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'rec-mig',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

it('creates the recurring_series, recurring_series_occurrences and recurring_series_transitions tables', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('recurring_series'))->toBeTrue();
    expect($schema->hasTable('recurring_series_occurrences'))->toBeTrue();
    expect($schema->hasTable('recurring_series_transitions'))->toBeTrue();
});

it('adds recurring_detection_window_months and recurring_income_min_amount_minor columns to users with documented defaults', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasColumn('users', 'recurring_detection_window_months'))->toBeTrue();
    expect($schema->hasColumn('users', 'recurring_income_min_amount_minor'))->toBeTrue();

    $created = User::query()->create([
        'username' => 'rec-defaults',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $fresh = User::query()->findOrFail($created->id);

    expect((int) $fresh->recurring_detection_window_months)->toBe(2);
    expect((int) $fresh->recurring_income_min_amount_minor)->toBe(200000);
});

it('rejects an invalid recurring_series.state value via the SQLite trigger', function (): void {
    $caught = null;
    try {
        $this->db->connection()->table('recurring_series')->insert([
            'user_id' => $this->user->id,
            'direction' => 'expense',
            'detected_name' => 'Trigger probe',
            'state' => 'garbage',
            'cadence' => 'monthly',
            'latest_amount_minor' => -999,
            'latest_currency' => 'EUR',
            'variance_tolerance_percent' => 25,
            'cluster_key' => 'probe',
            'created_at' => '2026-05-18 00:00:00',
            'updated_at' => '2026-05-18 00:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->getMessage())->toContain('Invalid recurring_series.state value');

    expect(
        $this->db->connection()->table('recurring_series')->count(),
    )->toBe(0);
});

it('rejects an update that flips recurring_series.state to an invalid value', function (): void {
    $id = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'Update probe',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'update-probe',
        'created_at' => '2026-05-18 00:00:00',
        'updated_at' => '2026-05-18 00:00:00',
    ]);

    $caught = null;
    try {
        $this->db->connection()->table('recurring_series')
            ->where('id', $id)
            ->update(['state' => 'wrong']);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->getMessage())->toContain('Invalid recurring_series.state value');

    $row = $this->db->connection()->table('recurring_series')->where('id', $id)->first();
    expect($row?->state)->toBe('pending');
});

it('enforces UNIQUE(user_id, direction, cluster_key, latest_currency) on recurring_series', function (): void {
    $base = [
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'Netflix',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'netflix|monthly|EUR',
        'created_at' => '2026-05-18 00:00:00',
        'updated_at' => '2026-05-18 00:00:00',
    ];

    $this->db->connection()->table('recurring_series')->insert($base);

    $caught = null;
    try {
        $this->db->connection()->table('recurring_series')->insert($base);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($this->db->connection()->table('recurring_series')->count())->toBe(1);
});

it('hydrates RecurringSeries.latestFundingChainLink to the related ChainLink or null', function (): void {
    $account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN main',
        'slug' => 'rec-mig-asn',
        'kind' => 'bank',
        'iban' => 'NL00ASNB',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rec-mig.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => '2026-05-18 00:00:00',
        'status' => 'previewed',
    ]);

    $tx = Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'import_run_id' => $run->id,
        'fingerprint' => str_repeat('b', 64),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'netflix',
        'counterparty_name' => 'NETFLIX.COM',
        'normalization_version' => 1,
        'description' => 'monthly subscription',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
    ]);

    $link = ChainLink::query()->create([
        'user_id' => $this->user->id,
        'from_transaction_id' => $tx->id,
        'to_transaction_id' => null,
        'kind' => 'ics_bulk_settle',
        'state' => 'candidate',
        'confidence' => '0.910',
        'resolver' => 'auto',
        'evidence' => ['signature_hash' => str_repeat('c', 16), 'tolerance_used' => 'exceeded'],
    ]);

    $linkedSeries = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'Netflix',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'latest_funding_chain_link_id' => $link->id,
        'cluster_key' => 'netflix|monthly|EUR|linked',
    ]);

    $unlinkedSeries = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'Spotify',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1199,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'spotify|monthly|EUR|unlinked',
    ]);

    expect($linkedSeries->latestFundingChainLink)->toBeInstanceOf(ChainLink::class);
    expect($linkedSeries->latestFundingChainLink?->id)->toBe($link->id);
    expect($unlinkedSeries->latestFundingChainLink)->toBeNull();
});

it('casts RecurringSeries date and boolean columns to immutable + boolean types', function (): void {
    $series = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'Cast probe',
        'state' => 'snoozed',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cast-probe|monthly|EUR',
        'snoozed_until' => '2026-06-01 00:00:00',
        'next_expected_at' => '2026-06-05',
        'next_expected_confidence_low' => 1,
    ]);

    $fresh = RecurringSeries::query()->findOrFail($series->id);

    expect($fresh->snoozed_until)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->next_expected_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->next_expected_confidence_low)->toBeTrue();
});

it('allows inserting recurring_series_occurrences and recurring_series_transitions rows', function (): void {
    $series = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'Occurrences probe',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'occ-probe|monthly|EUR',
    ]);

    $account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN main',
        'slug' => 'rec-mig-occ-asn',
        'kind' => 'bank',
        'iban' => 'NL00ASNB',
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rec-occ.csv',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => '2026-05-18 00:00:00',
        'status' => 'previewed',
    ]);
    $tx = Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'import_run_id' => $run->id,
        'fingerprint' => str_repeat('e', 64),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'netflix',
        'counterparty_name' => 'NETFLIX.COM',
        'normalization_version' => 1,
        'description' => 'monthly',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
    ]);

    $occurrence = RecurringSeriesOccurrence::query()->create([
        'user_id' => $this->user->id,
        'recurring_series_id' => $series->id,
        'transaction_id' => $tx->id,
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1099,
        'observed_currency' => 'EUR',
    ]);

    expect($occurrence->observed_at)->toBeInstanceOf(CarbonImmutable::class);

    $transition = RecurringSeriesTransition::query()->create([
        'user_id' => $this->user->id,
        'recurring_series_id' => $series->id,
        'from_state' => 'pending',
        'to_state' => 'approved',
        'transition_reason' => 'user_action',
        'actor' => 'user',
        'transitioned_at' => '2026-05-18 00:00:00',
    ]);

    expect($transition->transitioned_at)->toBeInstanceOf(CarbonImmutable::class);
});
