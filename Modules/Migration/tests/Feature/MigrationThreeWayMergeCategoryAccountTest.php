<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Pipeline\ThreeWayMergeResolver;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Enums\MigrationEntityType;

uses(RefreshDatabase::class);

/*
 * Covers the category + account arms of ThreeWayMergeResolver — a source name
 * that diverged from the import baseline either auto-applies (local untouched)
 * or raises a conflict (local also diverged). The transaction/budget arms have
 * their own coverage; these exercise the two arms this enum touches that were
 * otherwise unhit.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = User::query()->create([
        'username' => 'twm-catacc',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

/**
 * Seeds the 3-way-merge fixture for one category or account: the live Beatrax
 * row (name = $localName), a source-map + baseline snapshot of $baselineName,
 * and a staging row carrying the re-import's $sourceName. Returns the new run
 * id to resolve against.
 */
function twmSeedName(User $user, string $entityType, string $localName, string $baselineName, string $sourceName): int
{
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(4));

    if ($entityType === MigrationEntityType::Account->value) {
        $beatraxId = $db->connection()->table('accounts')->insertGetId([
            'user_id' => $user->id, 'name' => $localName, 'slug' => 'twm-'.$suffix,
            'kind' => 'bank', 'iban' => 'NL00TWM'.strtoupper($suffix), 'default_currency' => 'EUR',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stagingTable = 'migration_staging_accounts';
    } else {
        $beatraxId = $db->connection()->table('categories')->insertGetId([
            'user_id' => $user->id, 'name' => $localName, 'slug' => 'twm-'.$suffix,
            'kind' => 'expense', 'display_order' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stagingTable = 'migration_staging_categories';
    }

    $externalId = 'twm-ext-'.$suffix;
    $mapId = $db->connection()->table('migration_source_map')->insertGetId([
        'user_id' => $user->id, 'source_product' => 'ynab4', 'source_entity_type' => $entityType,
        'source_external_id' => $externalId, 'beatrax_entity_type' => $entityType, 'beatrax_id' => $beatraxId,
        'natural_key' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $db->connection()->table('migration_import_baseline')->insert([
        'user_id' => $user->id, 'migration_source_map_id' => $mapId,
        'field_name' => 'name', 'baseline_value' => $baselineName, 'imported_at' => now(),
    ]);

    $newRun = MigrationRun::create([
        'user_id' => $user->id, 'source_product' => 'ynab4',
        'status' => 'parsed', 'original_filename' => 'twm-'.$suffix.'.zip',
    ]);
    $stagingRow = [
        'user_id' => $user->id, 'migration_run_id' => $newRun->id,
        'source_external_id' => $externalId, 'name' => $sourceName, 'kind' => 'expense',
    ];
    if ($stagingTable === 'migration_staging_accounts') {
        $stagingRow['currency'] = 'EUR';
    }
    $db->connection()->table($stagingTable)->insert($stagingRow);

    return (int) $newRun->id;
}

it('auto-applies a renamed category when the local name still matches the baseline', function (): void {
    $runId = twmSeedName($this->user, MigrationEntityType::Category->value, 'Groceries', 'Groceries', 'Food');

    $decision = app(ThreeWayMergeResolver::class)->resolve($runId, $this->user, 'ynab4');

    expect($decision->conflicts)->toBe([]);
    $applies = array_values(array_filter(
        $decision->applies,
        static fn (array $a): bool => $a['entityType'] === MigrationEntityType::Category->value,
    ));
    expect($applies)->toHaveCount(1)
        ->and($applies[0]['fields']['name'])->toBe('Food');
});

it('raises a category conflict when both the local name and the source name diverged from the baseline', function (): void {
    $runId = twmSeedName($this->user, MigrationEntityType::Category->value, 'Boodschappen', 'Groceries', 'Food');

    $decision = app(ThreeWayMergeResolver::class)->resolve($runId, $this->user, 'ynab4');

    expect($decision->applies)->toBe([]);
    expect($decision->conflicts)->toHaveCount(1)
        ->and($decision->conflicts[0]->entityType)->toBe(MigrationEntityType::Category->value)
        ->and($decision->conflicts[0]->localValue)->toBe('Boodschappen')
        ->and($decision->conflicts[0]->sourceValue)->toBe('Food');
});

it('auto-applies a renamed account when the local name still matches the baseline', function (): void {
    $runId = twmSeedName($this->user, MigrationEntityType::Account->value, 'Checking', 'Checking', 'Main Checking');

    $decision = app(ThreeWayMergeResolver::class)->resolve($runId, $this->user, 'ynab4');

    expect($decision->conflicts)->toBe([]);
    $applies = array_values(array_filter(
        $decision->applies,
        static fn (array $a): bool => $a['entityType'] === MigrationEntityType::Account->value,
    ));
    expect($applies)->toHaveCount(1)
        ->and($applies[0]['fields']['name'])->toBe('Main Checking');
});

it('raises an account conflict when both the local name and the source name diverged from the baseline', function (): void {
    $runId = twmSeedName($this->user, MigrationEntityType::Account->value, 'Betaalrekening', 'Checking', 'Main Checking');

    $decision = app(ThreeWayMergeResolver::class)->resolve($runId, $this->user, 'ynab4');

    expect($decision->applies)->toBe([]);
    expect($decision->conflicts)->toHaveCount(1)
        ->and($decision->conflicts[0]->entityType)->toBe(MigrationEntityType::Account->value)
        ->and($decision->conflicts[0]->localValue)->toBe('Betaalrekening')
        ->and($decision->conflicts[0]->sourceValue)->toBe('Main Checking');
});
