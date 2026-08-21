<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Database\Seeders\Demo\DemoTransactionSplitsSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Models\TransactionSplit;

// applySplit and the rows it takes are both private, so the rows come in by
// reflection. Three of the four outcomes are refusals: a drifted description, a
// moved parent amount or a missing category must skip the row rather than abort
// the run or write a split that does not sum to its parent.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create(['username' => 'dtss', 'password' => 'fixture', 'period_start_day' => 1]);

    $account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-dtss',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000077',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/dtss.xml',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $today = CarbonImmutable::now()->toDateString();
    $this->parent = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'description' => 'DEMO SPLIT PARENT',
        'counterparty_name' => 'Shop',
        'counterparty_normalized' => 'shop',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_repeat('e', 64),
        'fingerprint_version' => 1,
    ]);

    Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'groceries-dtss', 'kind' => 'expense', 'display_order' => 1]);

    /** @var DemoTransactionSplitsSeeder $seeder */
    $seeder = $this->app->make(DemoTransactionSplitsSeeder::class);
    $this->seeder = $seeder;
});

/**
 * @param  list<array{categoryPath: list<string>, minor: int, note: ?string}>  $legs
 */
function dtssApply(object $seeder, User $user, string $descriptionMatch, array $legs): void
{
    $method = new ReflectionMethod(DemoTransactionSplitsSeeder::class, 'applySplit');
    $method->invoke($seeder, $user, ['descriptionMatch' => $descriptionMatch, 'legs' => $legs]);
}

function dtssSplitCount(int $transactionId): int
{
    return TransactionSplit::query()->where('transaction_id', $transactionId)->count();
}

it('splits a parent whose legs sum to it exactly', function (): void {
    dtssApply($this->seeder, $this->user, 'DEMO SPLIT PARENT', [
        ['categoryPath' => ['Groceries'], 'minor' => -5000, 'note' => 'part one'],
        ['categoryPath' => ['Groceries'], 'minor' => -3000, 'note' => null],
    ]);

    expect(dtssSplitCount((int) $this->parent->id))->toBe(2);
});

it('skips a row whose description matches nothing', function (): void {
    dtssApply($this->seeder, $this->user, 'NO SUCH DESCRIPTION', [
        ['categoryPath' => ['Groceries'], 'minor' => -8000, 'note' => null],
    ]);

    expect(dtssSplitCount((int) $this->parent->id))->toBe(0);
});

it('skips a row whose legs no longer sum to the parent', function (): void {
    dtssApply($this->seeder, $this->user, 'DEMO SPLIT PARENT', [
        ['categoryPath' => ['Groceries'], 'minor' => -5000, 'note' => null],
        ['categoryPath' => ['Groceries'], 'minor' => -1000, 'note' => null],
    ]);

    expect(dtssSplitCount((int) $this->parent->id))->toBe(0);
});

it('writes nothing when a category path is not in the default tree', function (): void {
    // The legs sum correctly, so only the unknown category stops this.
    dtssApply($this->seeder, $this->user, 'DEMO SPLIT PARENT', [
        ['categoryPath' => ['Groceries'], 'minor' => -5000, 'note' => null],
        ['categoryPath' => ['Nowhere', 'Missing'], 'minor' => -3000, 'note' => null],
    ]);

    expect(dtssSplitCount((int) $this->parent->id))->toBe(0);
});

it('leaves an already-split parent alone on a second run', function (): void {
    $legs = [
        ['categoryPath' => ['Groceries'], 'minor' => -5000, 'note' => null],
        ['categoryPath' => ['Groceries'], 'minor' => -3000, 'note' => null],
    ];

    dtssApply($this->seeder, $this->user, 'DEMO SPLIT PARENT', $legs);
    dtssApply($this->seeder, $this->user, 'DEMO SPLIT PARENT', $legs);

    expect(dtssSplitCount((int) $this->parent->id))->toBe(2);
});
