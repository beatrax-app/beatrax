<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

function readableMerchantKey(): string
{
    return str_repeat('a', 8).bin2hex(random_bytes(28));
}

function readableMerchantImportRun(int $userId): int
{
    return (int) DB::table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/readable-merchant.csv',
        'sha256' => bin2hex(random_bytes(32)),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

function readableMerchantTransaction(int $userId, int $accountId, string $sealedName, string $key): int
{
    return (int) DB::table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => '2026-07-03',
        'booked_at' => '2026-07-03 12:00:00',
        'value_date' => '2026-07-03',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'counterparty_name' => $sealedName,
        'counterparty_normalized' => $key,
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => readableMerchantImportRun($userId),
        'source_row_index' => 0,
        'fingerprint' => bin2hex(random_bytes(32)),
        'fingerprint_version' => 1,
        'description' => 'readable-merchant fixture',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'readable-merchant-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->session = $this->enablesEncryptionForUser($this->user);
    $this->actingAs($this->user);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN readable-merchant',
        'slug' => 'readable-merchant-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->categoryId = (int) DB::table('categories')->insertGetId([
        'user_id' => null,
        'name' => 'Groceries readable-merchant',
        'slug' => 'readable-merchant-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 100,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);
    $this->codec = $codec;
    $this->sealed = $codec->encryptValue(
        'transactions',
        'counterparty_name',
        'Albert Heijn',
        (int) $this->user->id,
        $this->session,
    );
    $this->key = readableMerchantKey();
});

// merchants.name is knowingly plaintext and transactions.counterparty_name is
// sealed, so copying the stored bytes across put base64 in a displayed column.
it('writes a name a reader can read, not the sealed bytes', function (): void {
    expect($this->sealed)->not->toBe('Albert Heijn');

    $announced = [];
    /** @var Dispatcher $dispatcher */
    $dispatcher = $this->app->make(Dispatcher::class);
    $dispatcher->listen(EntityMutated::class, function (EntityMutated $e) use (&$announced): void {
        if ($e->table === 'merchants') {
            $announced[] = $e;
        }
    });

    $txId = readableMerchantTransaction((int) $this->user->id, (int) $this->account->id, $this->sealed, $this->key);
    $dispatcher->dispatch(new TransactionCategorized($txId, $this->categoryId, (int) $this->user->id));

    $stored = DB::table('merchants')->where('user_id', $this->user->id)->where('normalized_name', $this->key)->value('name');

    $names = array_values(array_filter(array_map(
        static fn (EntityMutated $e): mixed => $e->dirtyFields['name'] ?? null,
        $announced,
    )));

    expect($stored)->toBe('Albert Heijn')
        ->and($names)->not->toBeEmpty()
        ->and(array_unique($names))->toBe(['Albert Heijn']);
});

// No migration can repair these rows: a migration runs without an unlocked
// session and so holds no key. Categorising the merchant again is a moment
// one is held.
it('puts a row already holding sealed bytes back in the clear', function (): void {
    DB::table('merchants')->insert([
        'user_id' => $this->user->id,
        'name' => $this->sealed,
        'normalized_name' => $this->key,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $edits = [];
    /** @var Dispatcher $dispatcher */
    $dispatcher = $this->app->make(Dispatcher::class);
    $dispatcher->listen(EntityMutated::class, function (EntityMutated $e) use (&$edits): void {
        if ($e->table === 'merchants' && $e->mutationType === 'edit') {
            $edits[] = $e;
        }
    });

    $txId = readableMerchantTransaction((int) $this->user->id, (int) $this->account->id, $this->sealed, $this->key);
    $dispatcher->dispatch(new TransactionCategorized($txId, $this->categoryId, (int) $this->user->id));

    expect(DB::table('merchants')->where('normalized_name', $this->key)->value('name'))->toBe('Albert Heijn')
        ->and($edits)->toHaveCount(1)
        ->and($edits[0]->dirtyFields['name'] ?? null)->toBe('Albert Heijn');
});
