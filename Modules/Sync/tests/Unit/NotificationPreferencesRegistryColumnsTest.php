<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

/*
 * D-40 contract test for the `notification_preferences` table (D-34, 18-04),
 * mirroring SavedReportsRegistryColumnsTest's shape.
 *
 * Asserts requiredCreateColumns('notification_preferences') is a SUBSET of
 * the migration's actual NOT-NULL-without-default columns (excluding the
 * auto-increment primary key), and pins the exact expected set: user_id,
 * device_id. Every other column carries a DB-level default (booleans,
 * digest_cadence, reminder_lead_days, quiet_hours_from/to) so is deliberately
 * excluded even though several are NOT NULL — same pattern as
 * saved_reports.pinned / envelope_settings.overspend_mode.
 */

it('MergeRulesRegistry notification_preferences _create_required is a subset of the real NOT-NULL-without-default columns', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $columns = $connection->getSchemaBuilder()->getColumns('notification_preferences');

    /** @var list<string> $notNullWithoutDefault */
    $notNullWithoutDefault = collect($columns)
        ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
        ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
        ->pluck('name')
        ->all();

    $registry = new MergeRulesRegistry;
    $required = $registry->requiredCreateColumns('notification_preferences');

    expect($required)->not->toBeEmpty();

    $missing = array_diff($required, $notNullWithoutDefault);
    expect($missing)->toBe([], 'every _create_required string must match a real NOT-NULL-without-default column: '.implode(', ', $missing));

    expect($required)->toEqualCanonicalizing(['user_id', 'device_id']);
});
