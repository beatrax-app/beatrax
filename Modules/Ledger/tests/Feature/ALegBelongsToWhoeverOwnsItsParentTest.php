<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Support\SplitLegs;

uses(RefreshDatabase::class);

// `transaction_splits.user_id` is a nullable denormalised copy of the parent's.
// Three readers of this table asked three different questions of it: one matched
// it, one matched it OR null, one ignored it. The column that is actually
// authoritative is `transactions.user_id` -- NOT NULL, and forced onto every
// arriving row by the op-log applier, which ignores whatever the wire claimed.

function lbwUser(DatabaseManager $db, string $suffix): int
{
    return (int) User::query()->create([
        'username' => 'lbw-'.$suffix.'-'.bin2hex(random_bytes(3)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ])->id;
}

function lbwTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'LBW '.$suffix,
        'slug' => 'lbw-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/lbw-'.$suffix.'.xml',
        'sha256' => hash('sha256', 'lbw-'.$suffix),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'committed',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-03-04',
        'booked_at' => '2026-03-04 09:00:00',
        'value_date' => '2026-03-04',
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'lbw vendor',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'lbw-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
}

function lbwLeg(DatabaseManager $db, ?int $legUserId, int $transactionId, int $categoryId): int
{
    return (int) $db->connection()->table('transaction_splits')->insertGetId([
        'user_id' => $legUserId,
        'transaction_id' => $transactionId,
        'category_id' => $categoryId,
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'sort_order' => 0,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
}

/** @return list<int> */
function lbwOwnedLegIds(DatabaseManager $db, int $userId): array
{
    $query = $db->connection()->table('transaction_splits');
    SplitLegs::ownedBy($query, $userId);

    return array_map(intval(...), $query->orderBy('id')->pluck('id')->all());
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->categoryId = (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => null,
        'name' => 'LBW category',
        'slug' => 'lbw-category-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
});

it('leaves out a leg whose parent belongs to somebody else', function (): void {
    $reader = lbwUser($this->db, 'reader');
    $other = lbwUser($this->db, 'other');

    $mine = lbwLeg($this->db, $reader, lbwTransaction($this->db, $reader), $this->categoryId);
    lbwLeg($this->db, $other, lbwTransaction($this->db, $other), $this->categoryId);

    expect(lbwOwnedLegIds($this->db, $reader))->toBe([$mine]);
});

// The copy is not the authority. A leg on this reader's own transaction is this
// reader's leg whatever its own column says, and the sum invariant needs it:
// the legs of a parent sum to the parent, so a set missing one does not.
it('keeps a leg on this readers parent whatever the copy says', function (): void {
    $reader = lbwUser($this->db, 'reader');
    $other = lbwUser($this->db, 'other');

    $parent = lbwTransaction($this->db, $reader);

    $correct = lbwLeg($this->db, $reader, $parent, $this->categoryId);
    $mislabelled = lbwLeg($this->db, $other, $parent, $this->categoryId);
    $ownerless = lbwLeg($this->db, null, $parent, $this->categoryId);

    expect(lbwOwnedLegIds($this->db, $reader))->toBe([$correct, $mislabelled, $ownerless]);
});

// The correlation is aliased, because a caller may already have `transactions`
// joined in and an unaliased one would bind to that instead of to the leg's own
// parent -- which reads as every leg belonging to everybody.
it('survives a caller that has already joined the parents table', function (): void {
    $reader = lbwUser($this->db, 'reader');
    $other = lbwUser($this->db, 'other');

    $mine = lbwLeg($this->db, $reader, lbwTransaction($this->db, $reader), $this->categoryId);
    lbwLeg($this->db, $other, lbwTransaction($this->db, $other), $this->categoryId);

    $query = $this->db->connection()->table('transaction_splits')
        ->join('transactions', 'transactions.id', '=', 'transaction_splits.transaction_id');
    SplitLegs::ownedBy($query, $reader);

    $ids = array_map(intval(...), $query->orderBy('transaction_splits.id')->pluck('transaction_splits.id')->all());

    expect($ids)->toBe([$mine]);
});
