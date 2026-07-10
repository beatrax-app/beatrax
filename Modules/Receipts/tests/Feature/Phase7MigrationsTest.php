<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

it('creates the file_imports table with the expected columns + indexes', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    expect($schema->hasTable('file_imports'))->toBeTrue();

    $expected = [
        'id',
        'user_id',
        'source_kind',
        'source_filename',
        'provider_message_id',
        'internal_date',
        'sender_email',
        'sender_name',
        'subject',
        'eml_path',
        'status',
        'fetched_at',
        'created_at',
        'updated_at',
    ];
    foreach ($expected as $column) {
        expect($schema->hasColumn('file_imports', $column))->toBeTrue("file_imports.{$column} missing");
    }
});

it('rejects an invalid file_imports.status via the BEFORE INSERT trigger', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    seedFileImportUser($db);

    $insert = static function () use ($db): void {
        $db->connection()->table('file_imports')->insert([
            'user_id' => 1,
            'source_kind' => 'eml',
            'source_filename' => 'whatever.eml',
            'provider_message_id' => 'whatever@example.test',
            'internal_date' => '2026-05-17 00:00:00',
            'sender_email' => 'service@paypal.com',
            'sender_name' => null,
            'subject' => 'Receipt',
            'eml_path' => 'app/inbox/1/file-drop/2026/05/abc.eml',
            'status' => 'NOT-A-VALID-STATUS',
            'fetched_at' => '2026-05-17 00:00:00',
            'created_at' => '2026-05-17 00:00:00',
            'updated_at' => '2026-05-17 00:00:00',
        ]);
    };

    expect($insert)->toThrow(QueryException::class);
});

it('rejects an invalid file_imports.source_kind via the BEFORE INSERT trigger', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    seedFileImportUser($db);

    $insert = static function () use ($db): void {
        $db->connection()->table('file_imports')->insert([
            'user_id' => 1,
            'source_kind' => 'pdf',
            'source_filename' => 'whatever.pdf',
            'provider_message_id' => 'whatever@example.test',
            'internal_date' => '2026-05-17 00:00:00',
            'sender_email' => 'service@paypal.com',
            'sender_name' => null,
            'subject' => 'Receipt',
            'eml_path' => 'app/inbox/1/file-drop/2026/05/abc.eml',
            'status' => 'fetched',
            'fetched_at' => '2026-05-17 00:00:00',
            'created_at' => '2026-05-17 00:00:00',
            'updated_at' => '2026-05-17 00:00:00',
        ]);
    };

    expect($insert)->toThrow(QueryException::class);
});

it('adds the matcher_key nullable column to inbox_messages', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    expect($schema->hasColumn('inbox_messages', 'matcher_key'))->toBeTrue();
});

it('creates the categorization_rules table with the redesigned parent-rule columns', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    expect($schema->hasTable('categorization_rules'))->toBeTrue();

    // The 2026_07_06 redesign replaced the flat single-condition shape with a
    // multi-condition parent: field/match/value/category_id moved to the
    // rule_conditions/rule_actions child tables, and priority/combinator were
    // added. (New-schema behaviour is covered in depth by
    // Modules\Categorization\tests\Feature\RuleSchemaMigrationTest.)
    expect($schema->hasColumn('categorization_rules', 'priority'))->toBeTrue();
    expect($schema->hasColumn('categorization_rules', 'combinator'))->toBeTrue();
    expect($schema->hasColumn('categorization_rules', 'field'))->toBeFalse();
    expect($schema->hasColumn('categorization_rules', 'value'))->toBeFalse();

    seedFileImportUser($db);

    $row = [
        'user_id' => 1,
        'priority' => 0,
        'combinator' => 'all',
        'hits_count' => 0,
        'active' => 1,
        'notes' => null,
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ];

    // A well-formed parent row inserts cleanly under the new schema.
    $db->connection()->table('categorization_rules')->insert($row);
    expect($db->connection()->table('categorization_rules')->where('user_id', 1)->count())->toBe(1);
});

it('rejects an invalid categorization_rules.combinator via the BEFORE INSERT trigger', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    seedFileImportUser($db);

    // The old field/match enum triggers were retired by the 2026_07_06 redesign
    // and replaced with a combinator enum-guard trigger ('all' | 'any').
    $insert = static function () use ($db): void {
        $db->connection()->table('categorization_rules')->insert([
            'user_id' => 1,
            'priority' => 0,
            'combinator' => 'bogus',
            'hits_count' => 0,
            'active' => 1,
            'notes' => null,
            'created_at' => '2026-05-17 00:00:00',
            'updated_at' => '2026-05-17 00:00:00',
        ]);
    };

    expect($insert)->toThrow(QueryException::class);
});

it('adds receipt_conflict_resolution + auto_import_drop_folder to users with the locked default', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    expect($schema->hasColumn('users', 'receipt_conflict_resolution'))->toBeTrue();
    expect($schema->hasColumn('users', 'auto_import_drop_folder'))->toBeTrue();

    seedFileImportUser($db);
    $row = $db->connection()->table('users')->where('id', 1)->first();
    expect($row)->not->toBeNull();
    expect($row->receipt_conflict_resolution)->toBe('unset');
    expect((int) $row->auto_import_drop_folder)->toBe(0);
});

it('rejects an invalid users.receipt_conflict_resolution via the BEFORE UPDATE trigger', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    seedFileImportUser($db);

    $update = static function () use ($db): void {
        $db->connection()->table('users')->where('id', 1)->update([
            'receipt_conflict_resolution' => 'prefer-something-else',
        ]);
    };

    expect($update)->toThrow(QueryException::class);
});

it('creates the pending_enrichment_conflicts table and enforces the UNIQUE constraint', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    expect($schema->hasTable('pending_enrichment_conflicts'))->toBeTrue();

    seedFileImportUser($db);
    seedTransactionsAccount($db);

    seedImportRun($db);
    $db->connection()->table('transactions')->insert([
        'id' => 1,
        'user_id' => 1,
        'account_id' => 1,
        'type' => 'expense',
        'posted_at' => '2026-05-17',
        'booked_at' => '2026-05-17 00:00:00',
        'value_date' => '2026-05-17',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'counterparty_name' => 'Spotify',
        'counterparty_iban' => null,
        'counterparty_normalized' => 'spotify',
        'normalization_version' => 1,
        'description' => 'Spotify subscription',
        'source_format' => 'paypal-csv',
        'import_run_id' => 1,
        'source_row_index' => 0,
        'source_ref' => null,
        'fingerprint' => str_repeat('a', 64),
        'fingerprint_version' => 3,
        'category_id' => null,
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);

    $row = [
        'user_id' => 1,
        'transaction_id' => 1,
        'field_name' => 'counterparty_name',
        'stored_value' => 'SPOTIFY.COM',
        'incoming_value' => 'Spotify',
        'incoming_source_format' => 'paypal-receipt',
        'import_run_id' => null,
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ];

    $db->connection()->table('pending_enrichment_conflicts')->insert($row);

    $duplicate = static function () use ($db, $row): void {
        $db->connection()->table('pending_enrichment_conflicts')->insert($row);
    };
    expect($duplicate)->toThrow(QueryException::class);
});

it('adds auto_category_provenance JSON column to transactions', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    expect($schema->hasColumn('transactions', 'auto_category_provenance'))->toBeTrue();

    seedFileImportUser($db);
    seedTransactionsAccount($db);

    seedImportRun($db);
    $db->connection()->table('transactions')->insert([
        'user_id' => 1,
        'account_id' => 1,
        'type' => 'expense',
        'posted_at' => '2026-05-17',
        'booked_at' => '2026-05-17 00:00:00',
        'value_date' => '2026-05-17',
        'amount_minor' => -899,
        'currency' => 'EUR',
        'settled_amount_minor' => -899,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'counterparty_name' => 'Netflix',
        'counterparty_iban' => null,
        'counterparty_normalized' => 'netflix',
        'normalization_version' => 1,
        'description' => 'Netflix subscription',
        'source_format' => 'paypal-receipt',
        'import_run_id' => 1,
        'source_row_index' => 0,
        'source_ref' => 'O-00000000000000099',
        'fingerprint' => str_repeat('b', 64),
        'fingerprint_version' => 3,
        'category_id' => null,
        'auto_category_provenance' => json_encode([
            'source' => 'rule',
            'rule_id' => 1,
            'memory_id' => null,
            'category_id' => 1,
        ]),
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);

    $row = $db->connection()->table('transactions')
        ->where('counterparty_name', 'Netflix')
        ->first();
    expect($row)->not->toBeNull();
    expect($row->auto_category_provenance)->not->toBeNull();
    $decoded = json_decode((string) $row->auto_category_provenance, true);
    expect($decoded['source'] ?? null)->toBe('rule');
});

function seedFileImportUser(DatabaseManager $db): void
{
    $exists = $db->connection()->table('users')->where('id', 1)->exists();
    if ($exists) {
        return;
    }
    $db->connection()->table('users')->insert([
        'id' => 1,
        'username' => 'kaarthouder',
        'password' => password_hash('synthetic', PASSWORD_BCRYPT),
        'period_start_day' => 1,
        'remember_token' => null,
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);
}

function seedTransactionsAccount(DatabaseManager $db): void
{
    $db->connection()->table('currencies')->insertOrIgnore([
        'code' => 'EUR',
        'name' => 'Euro',
        'minor_unit' => 2,
    ]);
    $db->connection()->table('accounts')->insertOrIgnore([
        'id' => 1,
        'user_id' => 1,
        'name' => 'Fixture',
        'slug' => 'fixture',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);
}

function seedImportRun(DatabaseManager $db): void
{
    $exists = $db->connection()->table('import_runs')->where('id', 1)->exists();
    if ($exists) {
        return;
    }
    $db->connection()->table('import_runs')->insert([
        'id' => 1,
        'user_id' => 1,
        'source_format' => 'paypal-csv',
        'raw_file_path' => 'storage/app/imports/synthetic.csv',
        'sha256' => str_repeat('0', 64),
        'uploaded_at' => '2026-05-17 00:00:00',
        'confirmed_at' => '2026-05-17 00:00:00',
        'inserted_count' => 1,
        'duplicate_count' => 0,
        'error_count' => 0,
        'status' => 'confirmed',
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);
}
