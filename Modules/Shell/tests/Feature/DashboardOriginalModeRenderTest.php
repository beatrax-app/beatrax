<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    /** @var User $user */
    $user = User::query()->updateOrCreate(
        ['username' => 'fixture'],
        ['password' => 'fixture-password', 'period_start_day' => 1, 'default_currency_view' => 'eur_only'],
    );
    $this->user = $user;
    $this->actingAs($this->user);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $this->account = Account::query()->updateOrCreate(
        ['iban' => 'NL57ASNB0123456789'],
        [
            'user_id' => $this->user->id,
            'name' => 'ASN Fixture Account',
            'slug' => 'asn-fixture',
            'kind' => 'bank',
            'default_currency' => 'EUR',
        ],
    );

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/fixture.csv',
        'sha256' => '0000000000000000000000000000000000000000000000000000000000000000',
        'uploaded_at' => CarbonImmutable::parse('2026-05-01 12:00:00'),
        'inserted_count' => 0,
        'duplicate_count' => 0,
        'error_count' => 0,
        'status' => 'previewed',
    ]);

    $this->rowIndex = 0;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function makeDashboardRenderTxn(object $test, int $amountMinor, string $currency, string $postedAt): void
{
    $test->rowIndex++;
    $index = $test->rowIndex;
    $fingerprint = str_pad((string) $index, 64, '0', STR_PAD_LEFT);

    Transaction::create([
        'user_id' => $test->user->id,
        'account_id' => $test->account->id,
        'type' => $amountMinor > 0 ? 'income' : ($amountMinor < 0 ? 'expense' : 'adjustment'),
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
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
        'fingerprint' => $fingerprint,
        'fingerprint_version' => 1,
    ]);
}

it('renders three tiles labeled by currency caption in original mode', function (): void {
    $this->user->update(['default_currency_view' => 'original']);
    makeDashboardRenderTxn($this, -1299, 'EUR', '2026-05-08');

    $response = $this->get('/');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('>EUR<');
    expect($html)->toContain('>In<');
    expect($html)->toContain('>Out<');
    expect($html)->toContain('>Net<');
})->group('phase-3');

it('renders the original-mode tile rows in alphabetical order', function (): void {
    $this->user->update(['default_currency_view' => 'original']);

    // Seeded out of order, so an alphabetical page cannot be insertion order.
    makeDashboardRenderTxn($this, -1299, 'USD', '2026-05-05');
    makeDashboardRenderTxn($this, -999, 'GBP', '2026-05-07');
    makeDashboardRenderTxn($this, -500, 'EUR', '2026-05-09');

    $response = $this->get('/');
    $response->assertOk();
    $html = (string) $response->getContent();

    $eurPos = strpos($html, '>EUR<');
    $gbpPos = strpos($html, '>GBP<');
    $usdPos = strpos($html, '>USD<');

    expect($eurPos)->not->toBeFalse();
    expect($gbpPos)->not->toBeFalse();
    expect($usdPos)->not->toBeFalse();
    expect($eurPos)->toBeLessThan($gbpPos);
    expect($gbpPos)->toBeLessThan($usdPos);
})->group('phase-3');

it('renders the single In/Out/Net layout when default_currency_view is eur_only', function (): void {
    // beforeEach already seeds default_currency_view = 'eur_only'.
    makeDashboardRenderTxn($this, -1299, 'EUR', '2026-05-08');
    makeDashboardRenderTxn($this, -1500, 'USD', '2026-05-09');

    $response = $this->get('/');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->not->toContain('>USD<');
    expect($html)->not->toContain('>EUR<');
    expect(substr_count($html, '>In<'))->toBe(1);
    expect(substr_count($html, '>Out<'))->toBe(1);
    expect(substr_count($html, '>Net<'))->toBe(1);
})->group('phase-3');
