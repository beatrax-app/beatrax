<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Categorization\Public\Actions\AssignCategory;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
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

    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'display_order' => 30,
    ]);

    $this->eatingOut = Category::create([
        'user_id' => null,
        'name' => 'Eating out',
        'slug' => 'eating-out',
        'kind' => 'expense',
        'display_order' => 70,
    ]);

    $this->tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-03',
        'booked_at' => '2026-05-03 12:00:00',
        'value_date' => '2026-05-03',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ah amsterdam',
        'counterparty_name' => 'AH Amsterdam',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $this->importRun->id,
        'source_row_index' => 0,
        'fingerprint' => str_repeat('a', 64),
        'fingerprint_version' => 1,
    ]);
});

it('assigns a category to an uncategorized transaction owned by the user', function (): void {
    Event::fake([TransactionCategorized::class]);

    /** @var AssignsCategory $action */
    $action = $this->app->make(AssignsCategory::class);

    $affected = $action($this->tx->id, $this->groceries->id, $this->user);

    expect($affected)->toBe(1);
    expect(Transaction::find($this->tx->id)->category_id)->toBe($this->groceries->id);

    Event::assertDispatched(
        TransactionCategorized::class,
        fn (TransactionCategorized $e): bool => $e->transactionId === $this->tx->id
            && $e->categoryId === $this->groceries->id
            && $e->userId === $this->user->id,
    );
});

it('overrides an existing category when assigning a different one', function (): void {
    // Seed the row with an existing category directly so we can isolate the
    // override-event dispatch.
    Transaction::query()->where('id', $this->tx->id)
        ->update(['category_id' => $this->groceries->id]);

    Event::fake([TransactionCategorized::class]);

    /** @var AssignsCategory $action */
    $action = $this->app->make(AssignsCategory::class);
    $affected = $action($this->tx->id, $this->eatingOut->id, $this->user);

    expect($affected)->toBe(1);
    expect(Transaction::find($this->tx->id)->category_id)->toBe($this->eatingOut->id);

    Event::assertDispatched(
        TransactionCategorized::class,
        fn (TransactionCategorized $e): bool => $e->transactionId === $this->tx->id
            && $e->categoryId === $this->eatingOut->id,
    );
});

it('clears the category when called with null', function (): void {
    Transaction::query()->where('id', $this->tx->id)
        ->update(['category_id' => $this->groceries->id]);

    Event::fake([TransactionCategorized::class]);

    /** @var AssignsCategory $action */
    $action = $this->app->make(AssignsCategory::class);
    $affected = $action($this->tx->id, null, $this->user);

    expect($affected)->toBe(1);
    expect(Transaction::find($this->tx->id)->category_id)->toBeNull();

    Event::assertDispatched(
        TransactionCategorized::class,
        fn (TransactionCategorized $e): bool => $e->categoryId === null,
    );
});

it("refuses to update another user's transaction and does not fire the event", function (): void {
    Event::fake([TransactionCategorized::class]);

    $other = User::create([
        'username' => 'other',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    /** @var AssignsCategory $action */
    $action = $this->app->make(AssignsCategory::class);

    $affected = $action($this->tx->id, $this->groceries->id, $other);

    expect($affected)->toBe(0);
    expect(Transaction::find($this->tx->id)->category_id)->toBeNull();
    Event::assertNotDispatched(TransactionCategorized::class);
});

it('binds the AssignsCategory contract to AssignCategory', function (): void {
    $resolved = $this->app->make(AssignsCategory::class);

    expect($resolved)->toBeInstanceOf(AssignCategory::class);
});

it('readPriorProvenance returns null on a corrupt auto_category_provenance JSON column without crashing', function (): void {
    // Non-JSON bytes: a bare json_decode returns null silently, and a strict
    // JSON_THROW_ON_ERROR caller without a catch throws. The wrapper has to
    // swallow both and return null.
    DB::table('transactions')
        ->where('id', $this->tx->id)
        ->update(['auto_category_provenance' => '{not even close to json}']);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $result = AssignCategory::readPriorProvenance($db, $this->tx->id, $this->user->id);

    expect($result)->toBeNull();

    // A full reclassify still succeeds; the corrupt payload never propagates.
    /** @var AssignCategory $assign */
    $assign = $this->app->make(AssignCategory::class);
    $affected = ($assign)($this->tx->id, $this->groceries->id, $this->user);

    expect($affected)->toBe(1);
    expect(Transaction::find($this->tx->id)->category_id)->toBe($this->groceries->id);
});
