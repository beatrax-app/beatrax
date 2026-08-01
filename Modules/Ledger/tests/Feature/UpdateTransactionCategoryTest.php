<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\UpdateTransactionCategory;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN', 'slug' => 'asn', 'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789', 'default_currency' => 'EUR',
    ]);

    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->category = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Groceries', 'slug' => 'groceries', 'kind' => 'expense',
    ]);

    $this->tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-03', 'booked_at' => '2026-05-03 12:00:00', 'value_date' => '2026-05-03',
        'amount_minor' => -1299, 'currency' => 'EUR',
        'settled_amount_minor' => -1299, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ah amsterdam', 'normalization_version' => 1,
        'source_format' => 'asn-csv', 'import_run_id' => $this->importRun->id, 'source_row_index' => 0,
        'fingerprint' => str_repeat('a', 64), 'fingerprint_version' => 1,
    ]);
});

it('assigns a category to a transaction owned by the user', function (): void {
    $action = $this->app->make(UpdateTransactionCategory::class);

    $affected = $action($this->tx->id, $this->category->id, $this->user);

    expect($affected)->toBe(1);
    $this->actingAs($this->user);
    expect(Transaction::find($this->tx->id)->category_id)->toBe($this->category->id);
});

it('unsets a category when passed null', function (): void {
    $action = $this->app->make(UpdateTransactionCategory::class);
    $action($this->tx->id, $this->category->id, $this->user);

    $affected = $action($this->tx->id, null, $this->user);

    expect($affected)->toBe(1);
    $this->actingAs($this->user);
    expect(Transaction::find($this->tx->id)->category_id)->toBeNull();
});

it('refuses to update a transaction owned by a different user', function (): void {
    $other = User::create([
        'username' => 'other',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $action = $this->app->make(UpdateTransactionCategory::class);

    $affected = $action($this->tx->id, $this->category->id, $other);

    expect($affected)->toBe(0);
});

it('refuses to assign a category owned by a different user', function (): void {
    /** @var User $other */
    $other = User::create([
        'username' => 'other',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    /** @var Category $foreignCategory */
    $foreignCategory = Category::create([
        'user_id' => $other->id,
        'name' => 'Other groceries',
        'slug' => 'other-groceries',
        'kind' => 'expense',
    ]);

    $action = $this->app->make(UpdateTransactionCategory::class);
    $affected = $action($this->tx->id, $foreignCategory->id, $this->user);

    expect($affected)->toBe(0);
    $this->actingAs($this->user);
    expect(Transaction::find($this->tx->id)->category_id)->toBeNull();
});

it('allows assigning a global default-tree category (user_id = NULL)', function (): void {
    /** @var Category $globalCategory */
    $globalCategory = Category::create([
        'user_id' => null,
        'name' => 'Global category',
        'slug' => 'global-cat',
        'kind' => 'expense',
    ]);

    $action = $this->app->make(UpdateTransactionCategory::class);
    $affected = $action($this->tx->id, $globalCategory->id, $this->user);

    expect($affected)->toBe(1);
    $this->actingAs($this->user);
    expect(Transaction::find($this->tx->id)->category_id)->toBe($globalCategory->id);
});

it('binds the UpdatesTransactionCategory contract to UpdateTransactionCategory', function (): void {
    $resolved = $this->app->make(UpdatesTransactionCategory::class);

    expect($resolved)->toBeInstanceOf(UpdateTransactionCategory::class);
});
