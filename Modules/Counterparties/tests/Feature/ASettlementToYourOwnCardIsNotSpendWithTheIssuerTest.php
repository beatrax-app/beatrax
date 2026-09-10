<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Ledger\Public\Enums\Currency;

// The alias bridge retypes a settlement to the reader's own card as a
// transfer, and leaves counterparty_id pointing at the issuer that resolved
// before it. Both counterparty roll-ups filter on nothing but the id, so the
// EUR 225.00 the reader moved onto their own card was added to what they had
// "spent with" the issuer — on top of every charge the settlement pays off.

function settlementUser(): User
{
    return User::query()->create([
        'username' => 'settlement-'.bin2hex(random_bytes(3)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
}

function settlementCounterparty(DatabaseManager $db, int $userId, string $slug): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'slug' => $slug, 'display_name' => 'International Card Services',
        'merchant_name' => 'International Card Services', 'type' => 'bank',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function settlementRow(DatabaseManager $db, int $userId, int $cpId, string $type, int $minor, string $date = '2026-08-01'): void
{
    $hex = bin2hex(random_bytes(5));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN '.$hex, 'slug' => 'stl-'.$hex, 'kind' => 'bank',
        'iban' => 'NL00STL'.strtoupper($hex), 'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/stl-'.$hex.'.csv',
        'sha256' => hash('sha256', 'stl-'.$hex), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'committed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'counterparty_id' => $cpId, 'category_id' => null,
        'fingerprint' => hash('sha256', 'stl-fp-'.$hex), 'fingerprint_version' => 3,
        'posted_at' => $date, 'booked_at' => $date.' 12:00:00', 'value_date' => $date,
        'amount_minor' => $minor, 'currency' => Currency::Eur->value,
        'settled_amount_minor' => $minor, 'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'ics', 'counterparty_name' => 'ICS',
        'normalization_version' => 1, 'description' => 'fixture',
        'type' => $type, 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->user = settlementUser();
    $this->actingAs($this->user);

    $this->cpId = settlementCounterparty($db, (int) $this->user->id, 'ics');
    // What the reader genuinely paid the issuer, and what they merely moved.
    settlementRow($db, (int) $this->user->id, $this->cpId, 'fee', -150);
    settlementRow($db, (int) $this->user->id, $this->cpId, 'transfer_out', -22500);
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

it('counts only what crossed, on the index row', function (): void {
    $row = app(CounterpartyIndexQuery::class)->forUser($this->user)->first();

    expect($row->total12mMinor)->toBe(-150);
});

it('counts only what crossed, in the month the sparkline draws', function (): void {
    $row = app(CounterpartyIndexQuery::class)->forUser($this->user)->first();

    expect($row->sparkline[11])->toBe(-150);
});

it('counts only what crossed, on the profile', function (): void {
    expect(app(CounterpartyProfileQuery::class)->bySlug($this->user, 'ics')->total12mMinor)->toBe(-150);
});
