<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;

uses(RefreshDatabase::class);

// accumulate() replaces the rows at cursor 0 and appends at a cursor it has not
// seen, so a payload naming a cursor ALREADY in appendedCursorIds is the one
// shape where neither happens and a forged row survives into the phone card
// list, which hands its minor/currency pair to Money::ofMinor().
const ACCUMULATED_ROWS_GUARD_BYPASS = [
    'cursorId' => 7,
    'appendedCursorIds' => [7 => true],
];

function accumulatedRowsSnapshot(string $pageHtml): string
{
    $matches = PatternScan::all('/wire:snapshot="([^"]*)"/', $pageHtml);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"ledger.transactions-list"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the transactions list on /transactions.');
}

/**
 * @param  array<string, mixed>  $updates
 */
function accumulatedRowsTamper(string $snapshot, array $updates): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [],
        ]],
    ]);
}

/**
 * @return array<string, mixed>
 */
function accumulatedRowsForgedRow(string $currency): array
{
    return [
        'id' => 1,
        'postedAt' => '2026-01-01',
        'counterpartyName' => null,
        'counterpartySlug' => null,
        'categoryId' => null,
        'amountMinor' => 1,
        'amountCurrency' => $currency,
        'secondaryMinor' => null,
        'secondaryCurrency' => null,
    ];
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'accumulated-rows',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'ASN account',
        'slug' => 'accumulated-rows-asn',
        'kind' => 'bank',
        'iban' => 'NL03ASNB0123450002',
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/accumulated-rows.xml',
        'sha256' => hash('sha256', 'accumulated-rows'),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $this->user->id,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'accumulated-rows-row'),
        'fingerprint_version' => 3,
        'posted_at' => now()->toDateString(),
        'booked_at' => now()->toDateTimeString(),
        'value_date' => now()->toDateString(),
        'amount_minor' => -1234,
        'currency' => 'EUR',
        'settled_amount_minor' => -1234,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'fixture',
        'counterparty_name' => 'Fixture',
        'normalization_version' => 1,
        'description' => 'accumulated rows fixture',
        'type' => 'expense',
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'status' => 'cleared',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $this->snapshot = accumulatedRowsSnapshot($this->get('/transactions')->assertOk()->getContent());
});

it('refuses a row denominated in a code no currency answers to however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    accumulatedRowsTamper($this->snapshot, [
        ...ACCUMULATED_ROWS_GUARD_BYPASS,
        'accumulatedRows' => [accumulatedRowsForgedRow('ZZZ')],
    ])->assertForbidden();
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

it('refuses a row that is not a row however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    accumulatedRowsTamper($this->snapshot, [
        ...ACCUMULATED_ROWS_GUARD_BYPASS,
        'accumulatedRows' => ['zzz'],
    ])->assertForbidden();
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

it('refuses the append guard on its own, which is what let the row through', function (): void {
    accumulatedRowsTamper($this->snapshot, ['appendedCursorIds' => [7 => true]])->assertForbidden();
});

it('leaves the address-bar filters the browser is supposed to write alone', function (): void {
    accumulatedRowsTamper($this->snapshot, ['filterAccounts' => [1]])->assertOk();
});

it('throws rather than accepting a write to either half of the accumulation', function (string $property): void {
    Livewire::test(TransactionsList::class)->set($property, []);
})->with([
    'the rows' => ['accumulatedRows'],
    'the append guard' => ['appendedCursorIds'],
])->throws(CannotUpdateLockedPropertyException::class);
