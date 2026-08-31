<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Tax\Public\Services\TaxCategoryWriter;
use Modules\Tax\Public\Services\TaxTagQuery;

uses(RefreshDatabase::class);

function rbsUser(string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'test-password',
        'is_developer' => false,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rbsTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'RBS ASN '.$suffix,
        'slug' => 'rbs-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rbs-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'rbs-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'rbs-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 00:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => -3000,
        'currency' => 'EUR',
        'settled_amount_minor' => -3000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'RBS Vendor BV',
        'counterparty_normalized' => 'rbs-vendor',
        'normalization_version' => 1,
        'description' => 'RBS test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function rbsTag(DatabaseManager $db, int $userId, int $txId, ?int $categoryId): void
{
    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'transaction_split_id' => null,
        'deduction_category_id' => $categoryId,
        'note' => null,
        'tax_year_override' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * The row array every tax-tagging surface builds, straight off the lookup that
 * feeds it — `HandlesTaxTagging::taxTagStateFor()` reads these two fields.
 *
 * @return array<string, mixed>
 */
function rbsBadgeRow(int $userId, int $txId): array
{
    $tags = app(TaxTagQuery::class)->forTransactionIds($userId, [$txId]);

    return [
        'id' => $txId,
        'taxTagged' => isset($tags[$txId]),
        'taxCategoryShortName' => $tags[$txId]->deductionCategoryShortName ?? null,
    ];
}

it('names the category a reader created in the picker, which has no short name and so read back as the generic tax word', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = rbsUser('rbs-inline-category');
    $txId = rbsTransaction($db, $user->id);

    // The picker's quick-add and the Settings add form both take a name only.
    $categoryId = app(TaxCategoryWriter::class)->add($user->id, 'Zakelijke reiskosten');
    rbsTag($db, $user->id, $txId, $categoryId);

    $this->blade(
        '<x-tax::tax-badge :transaction="$transaction" :showAlways="true" />',
        ['transaction' => rbsBadgeRow($user->id, $txId)],
    )
        ->assertSee('>Zakelijke reiskosten</button>', false)
        ->assertDontSee('>'.trans('tax::badge.default_label').'</button>', false);
});

it('still prefers the short name when the category carries one', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = rbsUser('rbs-short-name');
    $txId = rbsTransaction($db, $user->id);

    $categoryId = app(TaxCategoryWriter::class)->add($user->id, 'Zorgkosten boven drempel', 'Zorgkosten');
    rbsTag($db, $user->id, $txId, $categoryId);

    $this->blade(
        '<x-tax::tax-badge :transaction="$transaction" :showAlways="true" />',
        ['transaction' => rbsBadgeRow($user->id, $txId)],
    )
        ->assertSee('>Zorgkosten</button>', false)
        ->assertDontSee('>Zorgkosten boven drempel</button>', false);
});

it('keeps the generic word for a tag that names no category at all', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = rbsUser('rbs-no-category');
    $txId = rbsTransaction($db, $user->id);
    rbsTag($db, $user->id, $txId, null);

    $this->blade(
        '<x-tax::tax-badge :transaction="$transaction" :showAlways="true" />',
        ['transaction' => rbsBadgeRow($user->id, $txId)],
    )->assertSee('>'.trans('tax::badge.default_label').'</button>', false);
});

it('hands the badge label back from the whole-transaction lookup, not a null the caller has to guess at', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = rbsUser('rbs-lookup');
    $txId = rbsTransaction($db, $user->id);
    $categoryId = app(TaxCategoryWriter::class)->add($user->id, 'Vakliteratuur');
    rbsTag($db, $user->id, $txId, $categoryId);

    $tags = app(TaxTagQuery::class)->forTransactionIds($user->id, [$txId]);

    expect($tags[$txId]->deductionCategoryShortName)->toBe('Vakliteratuur');
});

it('hands the same label back from the leg-aware lookup', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = rbsUser('rbs-lookup-legs');
    $txId = rbsTransaction($db, $user->id);
    $categoryId = app(TaxCategoryWriter::class)->add($user->id, 'Telefoonkosten');
    rbsTag($db, $user->id, $txId, $categoryId);

    $tags = app(TaxTagQuery::class)->forTransactionIdsWithLegs($user->id, [$txId]);

    expect($tags[$txId.':whole']->deductionCategoryShortName)->toBe('Telefoonkosten');
});
