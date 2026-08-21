<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Events\EntityMutated;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'mem-writer',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->streaming = Category::create([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'streaming',
        'kind' => 'expense',
        'display_order' => 100,
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'display_order' => 200,
    ]);
});

function makeMemoryTransaction(User $user, Account $account, ImportRun $run, ?string $counterparty, string $normalized): Transaction
{
    static $idx = 0;
    $idx++;

    return Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-03',
        'booked_at' => '2026-05-03 12:00:00',
        'value_date' => '2026-05-03',
        'amount_minor' => -1000 - $idx,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000 - $idx,
        'settled_currency' => 'EUR',
        'counterparty_name' => $counterparty,
        'counterparty_normalized' => $normalized,
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $idx,
        'fingerprint' => str_pad((string) $idx, 64, 'm', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

function seedWriterMerchant(int $userId, string $normalized): int
{
    return (int) DB::table('merchants')->insertGetId([
        'user_id' => $userId,
        'name' => $normalized,
        'normalized_name' => $normalized,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

it('inserts a merchant_memories row on first TransactionCategorized for a new (merchant, category) tuple', function (): void {
    $merchantId = seedWriterMerchant($this->user->id, 'spotify premium');
    $tx = makeMemoryTransaction($this->user, $this->account, $this->run, 'Spotify Premium', 'spotify premium');

    event(new TransactionCategorized(
        transactionId: $tx->id,
        categoryId: $this->streaming->id,
        userId: $this->user->id,
    ));

    $row = DB::table('merchant_memories')
        ->where('user_id', $this->user->id)
        ->where('merchant_id', $merchantId)
        ->where('category_id', $this->streaming->id)
        ->first();

    expect($row)->not->toBeNull();
    expect((int) $row->occurrence_count)->toBe(1);
    expect($row->last_seen_at)->not->toBeNull();
});

it('increments occurrence_count atomically on repeat fire for the same triple', function (): void {
    $merchantId = seedWriterMerchant($this->user->id, 'spotify premium');
    $tx = makeMemoryTransaction($this->user, $this->account, $this->run, 'Spotify Premium', 'spotify premium');

    event(new TransactionCategorized($tx->id, $this->streaming->id, $this->user->id));
    event(new TransactionCategorized($tx->id, $this->streaming->id, $this->user->id));
    event(new TransactionCategorized($tx->id, $this->streaming->id, $this->user->id));

    $row = DB::table('merchant_memories')
        ->where('user_id', $this->user->id)
        ->where('merchant_id', $merchantId)
        ->where('category_id', $this->streaming->id)
        ->first();

    expect((int) $row->occurrence_count)->toBe(3);
});

it('is a no-op when categoryId is null (un-categorize)', function (): void {
    seedWriterMerchant($this->user->id, 'spotify premium');
    $tx = makeMemoryTransaction($this->user, $this->account, $this->run, 'Spotify Premium', 'spotify premium');

    event(new TransactionCategorized($tx->id, null, $this->user->id));

    expect(DB::table('merchant_memories')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('skips writing when the merchants row does not exist for (user, normalized_name)', function (): void {
    $tx = makeMemoryTransaction($this->user, $this->account, $this->run, 'Spotify Premium', 'spotify premium');
    // no seedWriterMerchant call — merchant absent.

    event(new TransactionCategorized($tx->id, $this->streaming->id, $this->user->id));

    expect(DB::table('merchant_memories')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('skips writing for the empty-counterparty sentinel', function (): void {
    $tx = makeMemoryTransaction($this->user, $this->account, $this->run, null, '_no_counterparty');

    event(new TransactionCategorized($tx->id, $this->streaming->id, $this->user->id));

    expect(DB::table('merchant_memories')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('writes a second memory row for a different category on the same merchant', function (): void {
    $merchantId = seedWriterMerchant($this->user->id, 'spotify premium');
    $tx = makeMemoryTransaction($this->user, $this->account, $this->run, 'Spotify Premium', 'spotify premium');

    event(new TransactionCategorized($tx->id, $this->streaming->id, $this->user->id));
    event(new TransactionCategorized($tx->id, $this->groceries->id, $this->user->id));

    $rows = DB::table('merchant_memories')
        ->where('user_id', $this->user->id)
        ->where('merchant_id', $merchantId)
        ->orderBy('category_id')
        ->get();

    expect($rows->count())->toBe(2);
    expect($rows->pluck('occurrence_count')->map(fn ($v): int => (int) $v)->all())->toBe([1, 1]);
});

it('falls back to atomic increment when the row already exists (race-safe upsert)', function (): void {
    // The second of two concurrent events: a row already at occurrence_count 1
    // must be incremented to 2 by the listener's QueryException fallback,
    // never clobbered or duplicated.
    $merchantId = seedWriterMerchant($this->user->id, 'spotify premium');
    $tx = makeMemoryTransaction($this->user, $this->account, $this->run, 'Spotify Premium', 'spotify premium');

    DB::table('merchant_memories')->insert([
        'user_id' => $this->user->id,
        'merchant_id' => $merchantId,
        'category_id' => $this->streaming->id,
        'occurrence_count' => 1,
        'last_seen_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    // The listener's insert attempt will hit the UNIQUE constraint;
    // the catch arm must atomically increment without throwing.
    event(new TransactionCategorized($tx->id, $this->streaming->id, $this->user->id));

    $row = DB::table('merchant_memories')
        ->where('user_id', $this->user->id)
        ->where('merchant_id', $merchantId)
        ->where('category_id', $this->streaming->id)
        ->first();

    expect((int) $row->occurrence_count)->toBe(2);
    expect(DB::table('merchant_memories')
        ->where('user_id', $this->user->id)
        ->where('merchant_id', $merchantId)
        ->where('category_id', $this->streaming->id)
        ->count())->toBe(1);
});

it("does not write a memory row when the event's userId does not match the transaction's owner", function (): void {
    $merchantId = seedWriterMerchant($this->user->id, 'spotify premium');
    $tx = makeMemoryTransaction($this->user, $this->account, $this->run, 'Spotify Premium', 'spotify premium');

    $foreign = User::create([
        'username' => 'foreign-writer',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    event(new TransactionCategorized(
        transactionId: $tx->id,
        categoryId: $this->streaming->id,
        userId: $foreign->id,
    ));

    // foreign user has no own (transaction_id, merchant) match → no row landed.
    expect(DB::table('merchant_memories')->where('user_id', $foreign->id)->count())->toBe(0);
    expect(DB::table('merchant_memories')->where('user_id', $this->user->id)->count())->toBe(0);
    expect($merchantId)->toBeGreaterThan(0);
});

it('announces every repeat sighting, not only the first', function (): void {
    Event::fake([EntityMutated::class]);

    $merchantId = seedWriterMerchant($this->user->id, 'spotify premium');
    $tx = makeMemoryTransaction($this->user, $this->account, $this->run, 'Spotify Premium', 'spotify premium');

    event(new TransactionCategorized($tx->id, $this->streaming->id, $this->user->id));
    event(new TransactionCategorized($tx->id, $this->streaming->id, $this->user->id));
    event(new TransactionCategorized($tx->id, $this->streaming->id, $this->user->id));

    // The insert branch dispatched; the update branch did not, so a peer was
    // told the merchant existed and never that it had been seen again — and
    // the count is what breaks the tie between two remembered categories.
    Event::assertDispatched(
        EntityMutated::class,
        static fn (EntityMutated $e): bool => $e->table === 'merchant_memories' && $e->mutationType === 'edit'
            && $e->incrementFields === ['occurrence_count']
            && ($e->dirtyFields['occurrence_count'] ?? null) === 1,
    );

    $edits = 0;
    Event::assertDispatched(EntityMutated::class, static function (EntityMutated $e) use (&$edits): bool {
        if ($e->table === 'merchant_memories' && $e->mutationType === 'edit') {
            $edits++;
        }

        return true;
    });

    expect($edits)->toBe(2);

    unset($merchantId);
});
