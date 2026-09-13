<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Services\TransactionRowDecorator;

uses(RefreshDatabase::class);

// The decorator takes the reader's id and spent it only on the category join,
// which constrains the parent-category alias and no leg at all. Whatever
// `transaction_splits.transaction_id` matched came back: the leg's amount, its
// currency and its category name, under whichever reader asked.

function dlrUser(string $suffix): int
{
    return (int) User::query()->create([
        'username' => 'dlr-'.$suffix.'-'.bin2hex(random_bytes(3)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ])->id;
}

function dlrCategory(DatabaseManager $db): int
{
    return (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => null,
        'name' => 'DLR category',
        'slug' => 'dlr-category-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
}

function dlrTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'DLR '.$suffix,
        'slug' => 'dlr-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/dlr-'.$suffix.'.xml',
        'sha256' => hash('sha256', 'dlr-'.$suffix),
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
        'counterparty_normalized' => 'dlr vendor',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'dlr-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
}

// The nullable `user_id` copy is filled in with the parent's owner, which is
// what a leg saved through the editor carries. The read must not consult it --
// matching it is a second wrong answer -- so a leg that looks right by that
// column and hangs off somebody else's parent is the row being asked about.
function dlrLeg(DatabaseManager $db, int $legUserId, int $transactionId, int $categoryId, int $minor): int
{
    return (int) $db->connection()->table('transaction_splits')->insertGetId([
        'user_id' => $legUserId,
        'transaction_id' => $transactionId,
        'category_id' => $categoryId,
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'sort_order' => 0,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->categoryId = dlrCategory($db);

    /** @var TransactionRowDecorator $decorator */
    $decorator = $this->app->make(TransactionRowDecorator::class);
    $this->decorator = $decorator;
});

it('returns nothing for a transaction belonging to somebody else', function (): void {
    $reader = dlrUser('reader');
    $other = dlrUser('other');

    $theirs = dlrTransaction($this->db, $other);
    dlrLeg($this->db, $other, $theirs, $this->categoryId, -2500);

    expect($this->decorator->legsFor([$theirs], $reader, 'EUR'))->toBe([]);
});

// Without this the assertion above passes on a reader that returns nothing at
// all, which is the same empty array and none of the same behaviour.
it('still returns this readers own legs', function (): void {
    $reader = dlrUser('reader');

    $mine = dlrTransaction($this->db, $reader);
    $legId = dlrLeg($this->db, $reader, $mine, $this->categoryId, -2500);

    $legs = $this->decorator->legsFor([$mine], $reader, 'EUR');

    expect(array_keys($legs))->toBe([$mine])
        ->and($legs[$mine])->toHaveCount(1)
        ->and($legs[$mine][0]['id'])->toBe($legId)
        ->and($legs[$mine][0]['amountMinor'])->toBe(-2500);
});

// One call, both parents: the component batches every accumulated row into a
// single read, so a foreign parent travels in the same id list as the reader's
// own and a scope that only works on a one-id list would not be one.
it('drops the foreign parent out of a batch that also carries this readers', function (): void {
    $reader = dlrUser('reader');
    $other = dlrUser('other');

    $mine = dlrTransaction($this->db, $reader);
    $theirs = dlrTransaction($this->db, $other);

    dlrLeg($this->db, $reader, $mine, $this->categoryId, -2500);
    dlrLeg($this->db, $other, $theirs, $this->categoryId, -9900);

    $legs = $this->decorator->legsFor([$mine, $theirs], $reader, 'EUR');

    expect(array_keys($legs))->toBe([$mine])
        ->and(array_column($legs[$mine], 'amountMinor'))->toBe([-2500]);
});
