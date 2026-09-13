<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

// A stale rate's sentence turns on the reader's online-refresh toggle, because
// `fx:refresh-rates` skips a reader who has it off: promising them the next
// refresh names a job nothing will run. The net-worth card passed the toggle
// and the four tiles above it did not, so one screen said both.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 12:00:00'));

    $db = app(DatabaseManager::class);
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();

    $this->user = User::query()->create([
        'username' => 'stale-tile-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
        'fx_online_enabled' => false,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'stale-tile-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/stale-tile.csv',
        'sha256' => hash('sha256', 'stale-tile-'.bin2hex(random_bytes(4))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // Fetched online once, and long enough ago to be stale. Not the bundled
    // snapshot: that has a sentence of its own and never reaches the branch
    // this is about.
    $db->connection()->table('exchange_rates')->updateOrInsert(
        ['base_currency' => 'EUR', 'quote_currency' => 'JPY', 'rate_date' => '2026-06-05', 'source' => 'ecb'],
        ['rate' => '0.00628536', 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()],
    );
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

function staleTileExpense(object $test, int $amountMinor, string $currency): void
{
    static $index = 0;
    $index++;

    Transaction::create([
        'user_id' => $test->user->id,
        'account_id' => $test->account->id,
        'type' => 'expense',
        'posted_at' => '2026-09-05',
        'booked_at' => '2026-09-05 12:00:00',
        'value_date' => '2026-09-05',
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_name' => 'Merchant '.$index,
        'counterparty_normalized' => 'merchant-'.$index,
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $test->run->id,
        'source_row_index' => $index,
        'fingerprint' => str_pad('st'.$index, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('tells a reader with online refresh off to turn it on, on the tiles as well as the card', function (): void {
    staleTileExpense($this, -480_000, 'JPY');

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('online refresh is off')
        ->and($html)->not->toContain('The next online refresh will update it');
});

it('promises the next refresh once the reader has it on', function (): void {
    $this->user->forceFill(['fx_online_enabled' => true])->save();
    staleTileExpense($this, -480_000, 'JPY');

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('The next online refresh will update it')
        ->and($html)->not->toContain('online refresh is off');
});
