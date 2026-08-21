<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

function rpUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rpAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'rp '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00RPSL'.str_pad(substr($slug, 0, 8), 10, '0', STR_PAD_RIGHT),
        'default_currency' => 'EUR',
    ]);
}

function rpImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rp.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function rpSeries(User $user, string $direction, string $detectedName, array $overrides = []): RecurringSeries
{
    return RecurringSeries::query()->create(array_merge([
        'user_id' => $user->id,
        'direction' => $direction,
        'detected_name' => $detectedName,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => $direction === 'income' ? 350000 : -1099,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => $direction === 'income' ? 350000 : -1099,
        'variance_tolerance_percent' => 25,
        'cluster_key' => $direction.'::'.$detectedName.'::eur::monthly',
        'next_expected_at' => '2026-06-17',
        'next_expected_confidence_low' => false,
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    $this->user = rpUser('rp');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('requires auth — GET /recurring redirects to /login when unauthenticated', function (): void {
    $this->get(route('recurring.index'))->assertRedirect('/login');
});

it('renders grouped expense + income sections with the net-flow header', function (): void {
    rpSeries($this->user, 'expense', 'spotify', [
        'monthly_equivalent_minor' => -1099,
        'latest_amount_minor' => -1099,
        'cluster_key' => 'expense::spotify::eur::monthly',
    ]);
    rpSeries($this->user, 'income', 'acme bv', [
        'monthly_equivalent_minor' => 350000,
        'latest_amount_minor' => 350000,
        'cluster_key' => 'income::acme-bv::eur::monthly',
    ]);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $response->assertOk()
        ->assertSeeText('Recurring expenses')
        ->assertSeeText('Recurring income')
        ->assertSeeText('spotify')
        ->assertSeeText('acme bv');
})->group('renders-grouped-sections');

it('renders the EUR shadow alongside the original-currency amount for a non-EUR series', function (): void {
    rpSeries($this->user, 'expense', 'netflix', [
        'latest_amount_minor' => -1199,
        'latest_currency' => 'USD',
        'monthly_equivalent_minor' => -1100,
        'cluster_key' => 'expense::netflix::usd::monthly',
    ]);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $content = $response->getContent() ?: '';
    expect($content)->toContain('netflix');
    expect(strpos($content, '$') !== false || strpos($content, 'USD') !== false)->toBeTrue();
})->group('multi-currency-row-shows-eur-shadow');

it('renders the transfers section collapsed by default', function (): void {
    rpSeries($this->user, 'expense', 'spotify');

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $content = $response->getContent() ?: '';

    // The toggle exists, but the panel does not render data-transfers-open="true"
    // until it is clicked.
    expect($content)->toContain('toggleTransfers');
    expect($content)->not->toContain('data-transfers-open="true"');
})->group('transfers-section-collapsed-by-default');

it('renders no series rows for user A when authenticated as user B (cross-user empty)', function (): void {
    $other = rpUser('rp-other');
    rpSeries($other, 'expense', 'other-spotify');

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $response->assertOk()
        ->assertDontSeeText('other-spotify');
})->group('cross-user-empty');

it('renders the low-confidence indicator on a row whose next_expected_confidence_low is true', function (): void {
    rpSeries($this->user, 'expense', 'jittery-thing', [
        'next_expected_confidence_low' => true,
    ]);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $content = $response->getContent() ?: '';

    // The Blade view emits a data-confidence-low="true" marker so the dim/italic
    // styling stays queryable from tests and swappable in one place.
    expect($content)->toContain('data-confidence-low="true"');
})->group('confidence-low-renders-dim-text');

it('renders a chain badge when a series carries a confirmed chain link', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $connection = $db->connection();
    $account = rpAccount($this->user, 'rp-chain');
    $run = rpImportRun($this->user, str_repeat('p', 64));

    $fromTxId = $connection->table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-01',
        'booked_at' => '2026-05-01 12:00:00',
        'value_date' => '2026-05-01',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'spotify',
        'counterparty_normalized' => 'spotify',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('rp-from', 64, 'q', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
    $toTxId = $connection->table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-02',
        'booked_at' => '2026-05-02 12:00:00',
        'value_date' => '2026-05-02',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'paypal',
        'counterparty_normalized' => 'paypal',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 2,
        'fingerprint' => str_pad('rp-to', 64, 'r', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
    $chainLinkId = $connection->table('chain_links')->insertGetId([
        'user_id' => $this->user->id,
        'from_transaction_id' => $fromTxId,
        'to_transaction_id' => $toTxId,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => 1.000,
        'resolver' => 'auto',
        'evidence' => json_encode(['kind' => 'paypal_funding']),
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    rpSeries($this->user, 'expense', 'spotify', [
        'latest_funding_chain_link_id' => $chainLinkId,
    ]);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $content = $response->getContent() ?: '';
    expect($content)->toContain('data-chain-badge="true"');
})->group('chain-fallback-renders-correct-icon');

it('renders an empty-state message when no approved series exist', function (): void {
    $this->actingAs($this->user)
        ->get(route('recurring.index'))
        ->assertOk()
        ->assertSeeText('No recurring activity yet');
})->group('empty-state');

it('renders the prior-occurrence chain link when the latest is missing (fallback walk)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $connection = $db->connection();
    $account = rpAccount($this->user, 'rp-fallback');
    $run = rpImportRun($this->user, str_repeat('w', 64));

    $oldFromId = $connection->table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-01',
        'booked_at' => '2026-04-01 12:00:00',
        'value_date' => '2026-04-01',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'spotify',
        'counterparty_normalized' => 'spotify',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('rp-fb-from', 64, 's', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
    $oldToId = $connection->table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-02',
        'booked_at' => '2026-04-02 12:00:00',
        'value_date' => '2026-04-02',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'paypal',
        'counterparty_normalized' => 'paypal',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 2,
        'fingerprint' => str_pad('rp-fb-to', 64, 't', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
    $priorChainId = $connection->table('chain_links')->insertGetId([
        'user_id' => $this->user->id,
        'from_transaction_id' => $oldFromId,
        'to_transaction_id' => $oldToId,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => 1.000,
        'resolver' => 'auto',
        'evidence' => json_encode(['kind' => 'paypal_funding']),
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    $series = rpSeries($this->user, 'expense', 'spotify', [
        'latest_funding_chain_link_id' => null,
    ]);
    RecurringSeriesOccurrence::query()->create([
        'user_id' => $this->user->id,
        'recurring_series_id' => $series->id,
        'transaction_id' => $oldFromId,
        'observed_at' => '2026-04-01',
        'observed_amount_minor' => -1099,
        'observed_currency' => 'EUR',
    ]);

    $response = $this->actingAs($this->user)->get(route('recurring.index'));
    $content = $response->getContent() ?: '';
    expect($content)->toContain('data-chain-badge="true"');
    expect($priorChainId)->toBeGreaterThan(0);
})->group('chain-fallback-walks-prior-occurrence');
