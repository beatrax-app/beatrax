<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\Services\FingerprintHealthCheck;

uses(RefreshDatabase::class);

// A drifted digest costs dedup, not the ledger: the rows are all there and the
// balances add up, so nothing on screen says anything is wrong until the next
// import of the same statement books a second copy.
function doctorDigestUser(): User
{
    return User::query()->create([
        'username' => 'ddig-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function doctorDigestTransaction(int $userId, int $version): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = (int) DB::table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN ddig', 'slug' => 'ddig-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);

    $runId = (int) DB::table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/ddig-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'ddig-'.$suffix), 'uploaded_at' => '2026-07-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);

    return (int) DB::table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'type' => 'expense',
        'posted_at' => '2026-07-03', 'booked_at' => '2026-07-03 12:00:00', 'value_date' => '2026-07-03',
        'amount_minor' => -1299, 'currency' => 'EUR',
        'settled_amount_minor' => -1299, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn', 'normalization_version' => 4,
        'source_format' => 'asn-csv', 'import_run_id' => $runId, 'source_row_index' => 0,
        'fingerprint' => hash('sha256', 'a-digest-for-values-this-row-does-not-have'.$suffix),
        'fingerprint_version' => $version,
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);
}

function doctorDigestRealign(int $txId): void
{
    $health = app(FingerprintHealthCheck::class);
    $row = DB::table('transactions')->where('id', $txId)->first();

    DB::table('transactions')->where('id', $txId)->update([
        'fingerprint' => app(FingerprintComposer::class)->composeTuple(
            new FingerprintTuple(
                userId: (int) $row->user_id,
                accountId: (int) $row->account_id,
                postedAtDate: substr((string) $row->posted_at, 0, 10),
                bookedAtDateTime: (string) $row->booked_at,
                amountMinor: (int) $row->amount_minor,
                currency: (string) $row->currency,
                counterpartyNormalized: (string) $row->counterparty_normalized,
                occurrenceOrdinal: (int) $row->occurrence_ordinal,
            ),
        ),
    ]);

    expect($health->severity())->toBe('ok');
}

it('says so when a digest no longer describes its row', function (): void {
    $user = doctorDigestUser();
    $txId = doctorDigestTransaction((int) $user->id, app(FingerprintComposer::class)->version());

    $health = app(FingerprintHealthCheck::class);

    expect($health->severity())->toBe('warning')
        ->and($health->message())->toContain('1 of 1')
        ->and($health->message())->toContain((string) $txId)
        ->and($health->message())->toContain('re-import would duplicate');
});

// The control, and the state every install should be in: realigning the one row
// flips the same check to ok, so a warning cannot be the only answer it gives.
it('says nothing is wrong once the digest describes the row again', function (): void {
    $user = doctorDigestUser();
    $txId = doctorDigestTransaction((int) $user->id, app(FingerprintComposer::class)->version());

    doctorDigestRealign($txId);

    expect(app(FingerprintHealthCheck::class)->message())->toContain('each describes its own row');
});

// A row an older version still stamps belongs to the sweep that moves it, and
// composing the current tuple over it would report every one of them.
it('does not count a row an older version still stamps', function (): void {
    $user = doctorDigestUser();
    doctorDigestTransaction((int) $user->id, 1);

    $health = app(FingerprintHealthCheck::class);

    expect($health->severity())->toBe('ok')
        ->and($health->message())->toContain('0 at the current version');
});

it('is ok on a ledger with nothing in it', function (): void {
    expect(app(FingerprintHealthCheck::class)->severity())->toBe('ok');
});
