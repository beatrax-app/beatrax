<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// One ¥480,000 expense moved the Out tile from EUR 2,101.82 to EUR 5,118.79.
// EUR 3,016.97 of that figure is yen priced at 0.00628536 from the snapshot
// bundled with the app, published 2026-06-05 and ninety-nine days old on the
// day below — and the tile showed the reader the number and nothing else. It
// was not a missing label: the rate, the source and the date were dropped
// before the tile could have read them.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 12:00:00'));

    /** @var User $user */
    $user = User::query()->updateOrCreate(
        ['username' => 'fx-out-tile'],
        [
            'password' => 'fixture-password-12chars',
            'period_start_day' => 1,
            'default_currency_view' => 'eur_only',
            'base_currency' => 'EUR',
        ],
    );
    $this->user = $user;
    $this->actingAs($user);

    $this->account = Account::query()->updateOrCreate(
        ['iban' => 'NL57ASNB0123456789'],
        [
            'user_id' => $user->id,
            'name' => 'ASN Fixture Account',
            'slug' => 'asn-fixture',
            'kind' => 'bank',
            'default_currency' => 'EUR',
        ],
    );

    $this->run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/fixture.csv',
        'sha256' => str_repeat('0', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
        'inserted_count' => 0,
        'duplicate_count' => 0,
        'error_count' => 0,
        'status' => 'previewed',
    ]);

    $this->rowIndex = 0;
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

function outTileExpense(object $test, int $amountMinor, string $currency): void
{
    $test->rowIndex++;
    $index = $test->rowIndex;

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
        'counterparty_name' => "Merchant {$index}",
        'counterparty_normalized' => "merchant {$index}",
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $test->run->id,
        'source_row_index' => $index,
        'fingerprint' => str_pad((string) $index, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('names the rate, the snapshot and the day behind the figure it moved', function (): void {
    outTileExpense($this, -210_182, 'EUR');
    outTileExpense($this, -480_000, 'JPY');

    $response = $this->get('/');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('5,118.79');

    expect($html)->toContain('data-fx-disclosure');
    expect($html)->toContain('1 JPY = 0.00628536 EUR');
    expect($html)->toContain('Bundled snapshot');
    expect($html)->toContain('months ago');
    expect($html)->toContain('Using a bundled snapshot rate more than 3 days old.');
});

// An all-euro period converted nothing, so there is nothing to disclose and
// the tiles are byte-identical to what they were.
it('says nothing about conversion on a period that converted nothing', function (): void {
    outTileExpense($this, -210_182, 'EUR');

    $response = $this->get('/');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('2,101.82');
    expect($html)->not->toContain('data-fx-disclosure');
    expect($html)->not->toContain('Bundled snapshot');
});
