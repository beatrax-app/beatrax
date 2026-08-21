<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Models\TransactionSplit;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::create(['username' => 'stsnf-owner', 'password' => 'fixture', 'period_start_day' => 1]);
    $this->other = User::create(['username' => 'stsnf-other', 'password' => 'fixture', 'period_start_day' => 1]);

    $this->account = Account::create([
        'user_id' => $this->owner->id,
        'name' => 'ASN',
        'slug' => 'asn-stsnf',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000042',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->owner->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/stsnf.xml',
        'sha256' => str_repeat('s', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->category = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries-stsnf',
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $today = CarbonImmutable::now()->toDateString();
    $this->transaction = Transaction::create([
        'user_id' => $this->owner->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Shop',
        'counterparty_normalized' => 'shop',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_repeat('f', 64),
        'fingerprint_version' => 1,
    ]);

    /** @var SaveTransactionSplit $splitter */
    $splitter = $this->app->make(SaveTransactionSplit::class);
    $this->splitter = $splitter;
});

/**
 * @return list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}>
 */
function stsnfLegs(int $categoryId): array
{
    return [
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -5000, 'note' => null],
        ['id' => null, 'category_id' => $categoryId, 'settled_amount_minor' => -3000, 'note' => null],
    ];
}

it('refuses to split a transaction that does not exist', function (): void {
    expect(fn () => $this->splitter->save($this->owner, 999_999, stsnfLegs($this->category->id)))
        ->toThrow(InvalidArgumentException::class, 'Transaction not found or not owned by user.');
});

it('refuses to split another user\'s transaction, with the same message', function (): void {
    // Identical to the missing-row message on purpose: a distinguishable
    // error would confirm the row exists.
    expect(fn () => $this->splitter->save($this->other, (int) $this->transaction->id, stsnfLegs($this->category->id)))
        ->toThrow(InvalidArgumentException::class, 'Transaction not found or not owned by user.');

    expect(TransactionSplit::query()->where('transaction_id', $this->transaction->id)->count())->toBe(0);
});

it('refuses to unsplit another user\'s transaction', function (): void {
    $this->splitter->save($this->owner, (int) $this->transaction->id, stsnfLegs($this->category->id));

    expect(fn () => $this->splitter->unsplit($this->other, (int) $this->transaction->id, $this->category->id))
        ->toThrow(InvalidArgumentException::class, 'Transaction not found or not owned by user.');

    expect(TransactionSplit::query()->where('transaction_id', $this->transaction->id)->count())->toBe(2);
});
