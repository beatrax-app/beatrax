<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;

uses(RefreshDatabase::class);

// transaction_splits declared no identity but its autoincrement, and sort_order
// is reassigned on every save, so the same leg was a different row on each
// device. split_uuid is minted once by the device that adds the leg and never
// rewritten; the row id is derived from it.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'splituuid-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $suffix = bin2hex(random_bytes(3));
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'splituuid-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.random_int(1000000000, 9999999999),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/splituuid.xml',
        'sha256' => hash('sha256', 'splituuid-'.$suffix),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'su-g-'.$suffix, 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'su-h-'.$suffix, 'kind' => 'expense', 'display_order' => 2]);

    $this->tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'import_run_id' => $run->id,
        'type' => 'expense',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'splituuid-tx-'.$suffix),
        'fingerprint_version' => 1,
    ]);
});

/**
 * @return list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}>
 */
function splitUuidLegs(int $groceries, int $household, int $first = -5000, int $second = -3000): array
{
    return [
        ['id' => null, 'category_id' => $groceries, 'settled_amount_minor' => $first, 'note' => 'weekly shop'],
        ['id' => null, 'category_id' => $household, 'settled_amount_minor' => $second, 'note' => null],
    ];
}

it('gives every leg an identity of its own and derives the id from it', function (): void {
    app(SaveTransactionSplit::class)->save($this->user, (int) $this->tx->id, splitUuidLegs((int) $this->groceries->id, (int) $this->household->id));

    $legs = DB::table('transaction_splits')->where('transaction_id', $this->tx->id)->orderBy('sort_order')->get();

    expect($legs)->toHaveCount(2);

    foreach ($legs as $leg) {
        expect($leg->split_uuid)->not->toBeNull()
            ->and((int) $leg->id)->toBe(DerivedRowId::for('transaction_splits', ['split_uuid' => $leg->split_uuid]));
    }

    expect($legs[0]->split_uuid)->not->toBe($legs[1]->split_uuid);
});

// The property the autoincrement could not give: a leg that moves position
// keeps the id every tax tag and every peer already names.
it('keeps a legs id when the save reorders it', function (): void {
    $action = app(SaveTransactionSplit::class);
    $action->save($this->user, (int) $this->tx->id, splitUuidLegs((int) $this->groceries->id, (int) $this->household->id));

    $before = DB::table('transaction_splits')->where('transaction_id', $this->tx->id)
        ->orderBy('sort_order')->pluck('id', 'sort_order')->all();

    $rows = DB::table('transaction_splits')->where('transaction_id', $this->tx->id)->orderBy('sort_order')->get();

    $action->save($this->user, (int) $this->tx->id, [
        ['id' => (int) $rows[1]->id, 'category_id' => (int) $rows[1]->category_id, 'settled_amount_minor' => -3000, 'note' => null],
        ['id' => (int) $rows[0]->id, 'category_id' => (int) $rows[0]->category_id, 'settled_amount_minor' => -5000, 'note' => 'weekly shop'],
    ]);

    $after = DB::table('transaction_splits')->where('transaction_id', $this->tx->id)
        ->orderBy('sort_order')->pluck('id', 'sort_order')->all();

    expect(array_values($after))->toBe(array_reverse(array_values($before)))
        ->and(DB::table('transaction_splits')->where('transaction_id', $this->tx->id)->count())->toBe(2);
});

it('announces the split uuid so the peer stores the same identity', function (): void {
    app(SaveTransactionSplit::class)->save($this->user, (int) $this->tx->id, splitUuidLegs((int) $this->groceries->id, (int) $this->household->id));

    $stored = (array) DB::table('transaction_splits')->where('transaction_id', $this->tx->id)->orderBy('sort_order')->first();

    // id, user_id and the timestamps are supplied by the applier and the
    // writer; every other stored column has to reach the peer.
    expect(array_keys($stored))->toContain('split_uuid')
        ->and($stored['split_uuid'])->not->toBeNull();
});
