<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\SplitSumHealthCheck;

uses(RefreshDatabase::class);

// The legs are refused locally unless they add up, so the only path that lands
// an unbalanced split is a peer's op -- and the rows all arrive, so nothing on
// screen says anything is wrong while every category rollup reads the legs.
function splitSumUser(): User
{
    return User::query()->create([
        'username' => 'dsum-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function splitSumTransaction(int $userId, int $settledMinor): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = (int) DB::table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN dsum', 'slug' => 'dsum-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);

    $runId = (int) DB::table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/dsum-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'dsum-'.$suffix), 'uploaded_at' => '2026-07-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);

    return (int) DB::table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'type' => 'expense',
        'posted_at' => '2026-07-03', 'booked_at' => '2026-07-03 12:00:00', 'value_date' => '2026-07-03',
        'amount_minor' => $settledMinor, 'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn', 'normalization_version' => 4,
        'source_format' => 'asn-csv', 'import_run_id' => $runId, 'source_row_index' => 0,
        'fingerprint' => hash('sha256', 'dsum-'.$suffix), 'fingerprint_version' => 1,
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);
}

function splitSumLeg(int $userId, int $transactionId, int $minor, string $currency = 'EUR'): int
{
    $categoryId = (int) DB::table('categories')->insertGetId([
        'user_id' => $userId, 'name' => 'Boodschappen '.bin2hex(random_bytes(3)),
        'slug' => 'dsum-'.bin2hex(random_bytes(4)), 'kind' => 'expense',
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);

    return (int) DB::table('transaction_splits')->insertGetId([
        'user_id' => $userId, 'transaction_id' => $transactionId, 'category_id' => $categoryId,
        'settled_amount_minor' => $minor, 'settled_currency' => $currency, 'sort_order' => 0,
        'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00',
    ]);
}

it('says nothing is split when nothing is', function (): void {
    splitSumUser();

    $health = app(SplitSumHealthCheck::class);

    expect($health->severity())->toBe('ok')
        ->and($health->message())->toBe('no transaction is split');
});

it('passes a split whose legs still add up to its transaction', function (): void {
    $user = splitSumUser();
    $transactionId = splitSumTransaction((int) $user->id, -8000);
    splitSumLeg((int) $user->id, $transactionId, -5000);
    splitSumLeg((int) $user->id, $transactionId, -3000);

    $health = app(SplitSumHealthCheck::class);

    expect($health->severity())->toBe('ok')
        ->and($health->message())->toBe('1 split, each adding up to its transaction');
});

it('names a transaction whose legs a peer raised past it', function (): void {
    $user = splitSumUser();
    $transactionId = splitSumTransaction((int) $user->id, -8000);
    $legId = splitSumLeg((int) $user->id, $transactionId, -5000);
    splitSumLeg((int) $user->id, $transactionId, -3000);

    // What a peer's SET does, with no gate in front of it: the leg moves and
    // the transaction does not.
    DB::table('transaction_splits')->where('id', $legId)->update(['settled_amount_minor' => -6000]);

    $health = app(SplitSumHealthCheck::class);

    expect($health->severity())->toBe('warning')
        ->and($health->message())->toContain('1 of 1 no longer add up to their transaction')
        ->and($health->message())->toContain('ids '.$transactionId)
        ->and($health->message())->toContain('their leg categories stopped counting')
        ->and($health->message())->toContain('re-open each split to rebalance it');
});

it('names a transaction its own amount outgrew', function (): void {
    $user = splitSumUser();
    $transactionId = splitSumTransaction((int) $user->id, -8000);
    splitSumLeg((int) $user->id, $transactionId, -5000);
    splitSumLeg((int) $user->id, $transactionId, -3000);

    // The other side of the same equation: settled_amount_minor merges as
    // plain LWW, so a peer can move the parent and leave the legs behind.
    DB::table('transactions')->where('id', $transactionId)->update(['settled_amount_minor' => -9500]);

    $health = app(SplitSumHealthCheck::class);

    expect($health->severity())->toBe('warning')
        ->and($health->message())->toContain('1 of 1 no longer add up to their transaction');
});

it('names a transaction whose legs are not all in its own currency', function (): void {
    $user = splitSumUser();
    $transactionId = splitSumTransaction((int) $user->id, -8000);
    splitSumLeg((int) $user->id, $transactionId, -5000);
    splitSumLeg((int) $user->id, $transactionId, -3000, 'USD');

    // -5000 EUR and -3000 USD is not -8000 of anything. Summing the two would
    // have called this balanced, which is the reading the currency guard
    // beside this one exists to refuse.
    $health = app(SplitSumHealthCheck::class);

    expect($health->severity())->toBe('warning')
        ->and($health->message())->toContain('1 of 1 no longer add up to their transaction');
});

it('leaves an unsplit transaction out of the count', function (): void {
    $user = splitSumUser();
    splitSumTransaction((int) $user->id, -8000);

    $splitId = splitSumTransaction((int) $user->id, -2500);
    splitSumLeg((int) $user->id, $splitId, -2500);

    $health = app(SplitSumHealthCheck::class);

    // One of the two transactions is split, and only that one is counted: a
    // denominator of 2 would mean the join reached rows with no legs at all.
    expect($health->severity())->toBe('ok')
        ->and($health->message())->toBe('1 split, each adding up to its transaction');
});
