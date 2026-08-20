<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Import\Public\Services\AliasMatchPreviewQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

beforeEach(function (): void {
    $this->userA = User::create([
        'username' => 'preview-a',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->userB = User::create([
        'username' => 'preview-b',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->accountA = Account::create([
        'user_id' => $this->userA->id,
        'name' => 'ASN A',
        'slug' => 'asn-preview-a',
        'kind' => 'bank',
        'iban' => 'NL16ASNB0000000001',
        'default_currency' => 'EUR',
    ]);
    $this->accountB = Account::create([
        'user_id' => $this->userB->id,
        'name' => 'ASN B',
        'slug' => 'asn-preview-b',
        'kind' => 'bank',
        'iban' => 'NL86ASNB0000000002',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = ImportRun::create([
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/preview-fixture.csv',
        'sha256' => str_repeat('p', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

function seedPreviewTransaction(
    int $accountId,
    int $userId,
    int $importRunId,
    int $rowIndex,
    string $description,
    string $bookedAt = '2026-05-25 10:00:00',
): void {
    DB::table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => substr($bookedAt, 0, 10),
        'booked_at' => $bookedAt,
        'value_date' => substr($bookedAt, 0, 10),
        'amount_minor' => -100 - $rowIndex,
        'currency' => 'EUR',
        'settled_amount_minor' => -100 - $rowIndex,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'counterparty_name' => 'fixture-'.$rowIndex,
        'counterparty_iban' => null,
        'counterparty_normalized' => 'fixture-'.$rowIndex,
        'normalization_version' => 1,
        'description' => $description,
        'category_id' => null,
        'source_format' => 'asn-csv',
        'import_run_id' => $importRunId,
        'source_row_index' => $rowIndex,
        'source_ref' => 'preview-'.$rowIndex,
        'fingerprint' => str_pad('p'.$userId.'-'.$rowIndex, 64, 'z'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'payment_type' => 'unknown',
        'created_at' => '2026-05-25 10:00:00',
        'updated_at' => '2026-05-25 10:00:00',
    ]);
}

it('returns the total count plus first 5 matched rows for a populated match', function (): void {
    for ($i = 0; $i < 10; $i++) {
        seedPreviewTransaction(
            $this->accountA->id,
            $this->userA->id,
            $this->importRun->id,
            $i,
            'BCK*SHELL PIETER NIEUW *0123',
        );
    }

    $svc = app(AliasMatchPreviewQuery::class);
    $result = $svc->preview('shell', $this->userA->id);

    expect($result->total)->toBe(10);
    expect(count($result->first5))->toBeLessThanOrEqual(5);
});

it('returns total 0 when nothing matches', function (): void {
    seedPreviewTransaction(
        $this->accountA->id,
        $this->userA->id,
        $this->importRun->id,
        0,
        'BCK*SHELL PIETER NIEUW *0123',
    );

    $svc = app(AliasMatchPreviewQuery::class);
    $result = $svc->preview('XYZ-NEVER-MATCHES', $this->userA->id);

    expect($result->total)->toBe(0);
    expect($result->first5)->toBe([]);
});

it('rejects patterns shorter than three characters with an explanatory empty message', function (): void {
    $svc = app(AliasMatchPreviewQuery::class);
    $result = $svc->preview('ab', $this->userA->id);

    expect($result->total)->toBe(0);
    expect($result->emptyMessage)->not->toBeNull();
});

it('does not mutate the transactions table during a preview scan', function (): void {
    seedPreviewTransaction(
        $this->accountA->id,
        $this->userA->id,
        $this->importRun->id,
        0,
        'BCK*SHELL PIETER NIEUW *0123',
    );

    $before = DB::table('transactions')->count();

    $svc = app(AliasMatchPreviewQuery::class);
    $svc->preview('shell', $this->userA->id);

    $after = DB::table('transactions')->count();
    expect($after)->toBe($before);
});

it('honours the 500-row scan cap with a larger history', function (): void {
    // All 600 match, so the cap — not the pattern — is what bounds `total`.
    for ($i = 0; $i < 600; $i++) {
        seedPreviewTransaction(
            $this->accountA->id,
            $this->userA->id,
            $this->importRun->id,
            $i,
            'BCK*SHELL PIETER NIEUW *0123',
        );
    }

    $svc = app(AliasMatchPreviewQuery::class);
    $result = $svc->preview('shell', $this->userA->id);

    expect($result->total)->toBeLessThanOrEqual(500);
});

it('excludes another user\'s transactions from the preview scan', function (): void {
    seedPreviewTransaction(
        $this->accountA->id,
        $this->userA->id,
        $this->importRun->id,
        0,
        'BCK*SHELL PIETER NIEUW *0123',
    );
    seedPreviewTransaction(
        $this->accountB->id,
        $this->userB->id,
        $this->importRun->id,
        1,
        'BCK*SHELL PIETER NIEUW *0999',
    );

    $svc = app(AliasMatchPreviewQuery::class);
    $result = $svc->preview('shell', $this->userA->id);

    expect($result->total)->toBe(1);
});
