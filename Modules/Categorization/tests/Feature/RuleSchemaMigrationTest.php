<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Categorization\Database\Seeders\DefaultCategorizationRuleSeeder;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

/**
 * @return array<string, mixed>
 */
function ruleSchemaTransactionFixture(int $accountId, int $importRunId, int $rowIndex): array
{
    return [
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => '2026-07-05',
        'booked_at' => '2026-07-05 10:00:00',
        'value_date' => '2026-07-05',
        'amount_minor' => -100 - $rowIndex,
        'currency' => 'EUR',
        'settled_amount_minor' => -100 - $rowIndex,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'counterparty_name' => 'Fixture-'.$rowIndex,
        'counterparty_iban' => null,
        'counterparty_normalized' => 'fixture-'.$rowIndex,
        'normalization_version' => 1,
        'description' => 'Fixture row '.$rowIndex,
        'category_id' => null,
        'source_format' => 'asn-csv',
        'import_run_id' => $importRunId,
        'source_row_index' => $rowIndex,
        'source_ref' => null,
        'fingerprint' => str_pad('rule-schema-'.$rowIndex, 64, 'z'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'payment_type' => 'unknown',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ];
}

function redesignCategorizationRulesMigration(): object
{
    return require base_path(
        'Modules/Categorization/Database/Migrations/2026_07_06_000001_redesign_categorization_rules_table.php',
    );
}

function migrateLegacyRulesMigration(): object
{
    return require base_path(
        'Modules/Categorization/Database/Migrations/2026_07_06_000005_migrate_existing_rules_to_conditions_actions.php',
    );
}

beforeEach(function (): void {
    $this->account = Account::create([
        'name' => 'ASN',
        'slug' => 'asn-rule-schema',
        'kind' => 'bank',
        'iban' => 'NL56ASNB9999900099',
        'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rule-schema.csv',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

it('rejects an invalid transactions.type value at the DB layer (trigger reinstalled after the field_provenance column-add)', function (): void {
    expect(fn () => DB::table('transactions')->insert([
        ...ruleSchemaTransactionFixture($this->account->id, $this->importRun->id, 1),
        'type' => 'NOT_A_TYPE',
    ]))->toThrow(QueryException::class, 'Invalid transactions.type value');
});

it('rejects an invalid rule_conditions.value_type value at the DB layer', function (): void {
    $rule = CategorizationRule::withoutGlobalScopes()->create([
        'priority' => 10,
        'combinator' => 'all',
        'active' => true,
        'hits_count' => 0,
    ]);

    expect(fn () => DB::table('rule_conditions')->insert([
        'rule_id' => $rule->id,
        'field' => 'merchant',
        'op' => 'contains',
        'value_type' => 'bogus',
        'value' => 'x',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]))->toThrow(QueryException::class, 'Invalid rule_conditions.value_type value');
});

it('rejects an invalid rule_actions.type value at the DB layer', function (): void {
    $rule = CategorizationRule::withoutGlobalScopes()->create([
        'priority' => 10,
        'combinator' => 'all',
        'active' => true,
        'hits_count' => 0,
    ]);

    expect(fn () => DB::table('rule_actions')->insert([
        'rule_id' => $rule->id,
        'position' => 0,
        'type' => 'bogus',
        'payload' => json_encode(['category_id' => 1]),
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]))->toThrow(QueryException::class, 'Invalid rule_actions.type value');
});

it('gives every seeded default rule exactly one condition and one category action', function (): void {
    app(DefaultCategoryTreeSeeder::class)->run();
    $user = User::create([
        'username' => 'rule-schema-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    app(DefaultCategorizationRuleSeeder::class)->run($user);

    $rules = CategorizationRule::withoutGlobalScopes()->where('user_id', $user->id)->get();
    expect($rules)->not->toBeEmpty();

    foreach ($rules as $rule) {
        expect($rule->conditions()->count())->toBe(1);
        expect($rule->actions()->count())->toBe(1);

        $action = $rule->actions()->firstOrFail();
        expect($action->type)->toBe('category');
        expect($action->payload)->toHaveKey('category_id');
        expect($action->payload['category_id'])->toBeInt();
    }

    $countBefore = $rules->count();
    app(DefaultCategorizationRuleSeeder::class)->run($user);
    expect(CategorizationRule::withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe($countBefore);
});

it('losslessly backfills legacy flat rule rows into one condition + one action each', function (): void {
    $category = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'rule-schema-groceries',
        'kind' => 'expense',
    ]);

    // Reverse the redesign to the flat shape so a legacy row can be inserted
    // exactly as it would have existed on a pre-redesign database.
    redesignCategorizationRulesMigration()->down();

    $legacyIds = [];
    $legacyFixture = [
        ['merchant', 'contains', 'Albert Heijn'],
        ['description', 'starts_with', 'IDEAL'],
    ];

    foreach ($legacyFixture as [$field, $match, $value]) {
        $legacyIds[] = DB::table('categorization_rules')->insertGetId([
            'field' => $field,
            'match' => $match,
            'value' => $value,
            'category_id' => $category->id,
            'active' => true,
            'hits_count' => 0,
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    // Re-apply the redesign, which stashes the legacy rows, then the backfill.
    redesignCategorizationRulesMigration()->up();
    migrateLegacyRulesMigration()->up();

    expect(Schema::hasTable('_legacy_categorization_rules'))->toBeFalse();

    foreach ($legacyIds as $id) {
        $parent = DB::table('categorization_rules')->where('id', $id)->first();
        expect($parent)->not->toBeNull();
        expect($parent->combinator)->toBe('all');
        expect((int) $parent->priority)->toBe($id * 10);

        expect(DB::table('rule_conditions')->where('rule_id', $id)->count())->toBe(1);
        expect(DB::table('rule_actions')->where('rule_id', $id)->count())->toBe(1);

        $action = DB::table('rule_actions')->where('rule_id', $id)->first();
        expect(json_decode((string) $action->payload, true))->toBe(['category_id' => $category->id]);
    }
});

it('migration 000005 down() never deletes user-authored rule_conditions/rule_actions rows (CR-03)', function (): void {
    $category = Category::create([
        'user_id' => null,
        'name' => 'Rent',
        'slug' => 'rule-schema-rent',
        'kind' => 'expense',
    ]);
    $user = User::create([
        'username' => 'rule-schema-rollback-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    // A rule authored after the backfill ran, indistinguishable at the schema
    // level from one 000005 wrote — the row a naive down() would destroy.
    $rule = CategorizationRule::withoutGlobalScopes()->create([
        'user_id' => $user->id,
        'priority' => 10,
        'combinator' => 'all',
        'active' => true,
        'notes' => null,
        'hits_count' => 0,
    ]);
    $rule->conditions()->create([
        'field' => 'merchant',
        'op' => 'contains',
        'value_type' => 'string',
        'value' => 'LANDLORD',
        'value2' => null,
    ]);
    $rule->actions()->create([
        'position' => 0,
        'type' => 'category',
        'payload' => ['category_id' => $category->id],
    ]);

    // migrate:rollback reaching 000005 in the field must be a safe no-op.
    migrateLegacyRulesMigration()->down();

    $survivingRule = DB::table('categorization_rules')->where('id', $rule->id)->first();
    expect($survivingRule)->not->toBeNull();
    // priority/combinator must NOT have been reset to column defaults.
    expect((int) $survivingRule->priority)->toBe(10);
    expect($survivingRule->combinator)->toBe('all');

    expect(DB::table('rule_conditions')->where('rule_id', $rule->id)->count())->toBe(1);
    expect(DB::table('rule_actions')->where('rule_id', $rule->id)->count())->toBe(1);

    $condition = DB::table('rule_conditions')->where('rule_id', $rule->id)->first();
    expect($condition->value)->toBe('LANDLORD');
});

it('MergeRulesRegistry describes the new categorization_rules/rule_conditions/rule_actions schema, not the dropped flat columns', function (): void {
    $rules = app(MergeRulesRegistry::class)->rules();

    expect($rules)->toHaveKey('categorization_rules');
    expect($rules['categorization_rules'])->toHaveKeys(['priority', 'combinator']);
    expect($rules['categorization_rules'])->not->toHaveKeys(['field', 'match', 'value', 'category_id']);
    // Empty and correctly so: _create_required lists the columns a replayed
    // CreateRow must carry, and priority, combinator, active and hits_count
    // all have DB defaults. The child tables below are the contrasting case.
    expect($rules['categorization_rules']['_create_required'])->toBe([]);

    expect($rules)->toHaveKey('rule_conditions');
    expect($rules['rule_conditions']['_create_required'])->not->toBeEmpty();
    expect($rules['rule_conditions']['_create_required'])
        ->toEqualCanonicalizing(['rule_id', 'field', 'op', 'value_type', 'value']);

    expect($rules)->toHaveKey('rule_actions');
    expect($rules['rule_actions']['_create_required'])->not->toBeEmpty();
    expect($rules['rule_actions']['_create_required'])
        ->toEqualCanonicalizing(['rule_id', 'position', 'type', 'payload']);
});
