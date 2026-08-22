<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nwidart\Modules\Contracts\RepositoryInterface;

uses(RefreshDatabase::class);

it('registers the Mobile module as enabled', function (): void {
    /** @var RepositoryInterface $modules */
    $modules = app(RepositoryInterface::class);

    $module = $modules->find('Mobile');
    expect($module)->not->toBeNull('Mobile module is not registered with nwidart/laravel-modules');
    expect($modules->isEnabled('Mobile'))->toBeTrue();
});

it('creates the mobile_sync_progress durable-cursor table with the expected columns and unique index', function (): void {
    // RefreshDatabase has already migrated the test schema; introspect it directly.
    $connection = app(DatabaseManager::class)->connection();
    $columns = collect($connection->getSchemaBuilder()->getColumns('mobile_sync_progress'))
        ->keyBy('name');

    foreach ([
        'id', 'user_id', 'peer_device_id', 'records_expected',
        'records_applied', 'last_hlc_l', 'last_hlc_c', 'phase',
        'created_at', 'updated_at',
    ] as $expectedColumn) {
        expect($columns->has($expectedColumn))->toBeTrue("Missing column: {$expectedColumn}");
    }

    expect($columns['user_id']['nullable'])->toBeFalse();
    expect($columns['records_applied']['nullable'])->toBeFalse();

    // Plain integers only for progress math, never a float or a double.
    $types = $columns->pluck('type_name')->map(static fn (string $t): string => strtolower($t));
    expect($types->filter(static fn (string $t): bool => str_contains($t, 'float') || str_contains($t, 'double')))
        ->toBeEmpty();

    // sqlite_master rather than Schema::getIndexes(), which is not reliable across
    // every driver in this Laravel version.
    $indexSql = $connection
        ->table('sqlite_master')
        ->where('type', 'index')
        ->where('tbl_name', 'mobile_sync_progress')
        ->pluck('sql')
        ->filter()
        ->implode("\n");
    expect(stripos($indexSql, 'unique'))->not->toBeFalse('Missing a UNIQUE index on mobile_sync_progress');
    expect(str_contains($indexSql, 'user_id') && str_contains($indexSql, 'peer_device_id'))->toBeTrue();
});

// This used to require Mobile, MobileUnit and MobileFeature all be declared, which
// pinned the bug: the latter two re-listed Mobile's own directories, and PHPUnit
// answers a file claimed twice with a warning that exits the run 1 with nothing
// failing.
it('declares the Mobile testsuite, and no two testsuites claim the same directory', function (): void {
    $config = new SimpleXMLElement((string) file_get_contents(base_path('phpunit.xml')));

    $names = [];
    $claims = [];

    foreach ($config->testsuites->testsuite as $suite) {
        $names[] = (string) $suite['name'];

        foreach ($suite->directory as $directory) {
            $claims[trim((string) $directory)][] = (string) $suite['name'];
        }
    }

    expect($names)->toContain('Mobile');

    $shared = array_filter($claims, static fn (array $owners): bool => count($owners) > 1);

    expect($shared)->toBe([], 'A directory claimed by two testsuites makes every run that loads both exit 1, '
        .'with no failing test to point at: '.json_encode($shared));
});

it('forward-registers every future Internal/Livewire/command FQCN in MobileServiceProvider', function (): void {
    $provider = (string) file_get_contents(base_path('Modules/Mobile/Providers/MobileServiceProvider.php'));

    // This used to assert the provider still carried a singletonIfExists()
    // helper. That helper guarded first-party classes on whether they existed,
    // which is never in doubt and turns a typo into a binding that silently
    // does not happen. What the test is named for is the roster below; the
    // guard that IS load-bearing names a vendor package absent from the
    // desktop root, and stays.
    expect($provider)->not->toContain('singletonIfExists');
    expect($provider)->toContain("class_exists('Native\\Mobile\\Facades\\SecureStorage')");

    foreach ([
        'LanSyncClient',
        'NetworkPolicyResolver',
        'MobileSyncTriggerService',
        'InitialSyncPuller',
        'BiometricUnlockBridge',
        'QrScanBridge',
        'MobileFirstLaunchBootstrap',
        'MobilePullCommand',
        'MobileLockScreen',
        'MobilePairingScan',
        'SetupProgressScreen',
        'SyncScreen',
    ] as $futureClass) {
        expect($provider)->toContain($futureClass);
    }
});
