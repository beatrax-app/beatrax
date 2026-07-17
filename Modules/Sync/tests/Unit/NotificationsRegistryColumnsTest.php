<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

/*
 * D-40 contract test for the `notifications` table (Req 11/12, 18-04),
 * mirroring SavedReportsRegistryColumnsTest's shape.
 *
 * Asserts requiredCreateColumns('notifications') is a SUBSET of the
 * migration's actual NOT-NULL-without-default columns, and pins the exact
 * expected set: id, user_id, title, body, trigger_type.
 *
 * `id` IS pinned as required — unlike every other registered table,
 * `notifications.id` is a manually assigned deterministic sha256 STRING
 * primary key (`$table->string('id', 64)->primary()`), not an autoincrement
 * surrogate. `OpLogReplayer`'s CREATE_ROW assembly never writes the pk
 * column into the INSERT payload on its own — every other table relies on
 * the DB's own autoincrement to fill it in — so for this table `id` MUST be
 * carried as an explicit field (`SyncCaptureListener::handleNotificationCreate`
 * does this) or a fresh device's `insertOrIgnore` silently drops the row on
 * the `id` NOT NULL constraint. `auto_increment` correctly reports false for
 * this column, so the standard reject-auto_increment filter below does NOT
 * exclude it — which is exactly right here.
 *
 * `state` carries a DB-level default('open') so it is excluded even though
 * NOT NULL (same pattern as saved_reports.pinned / envelope_settings
 * .overspend_mode's known-safe defaults) AND is deliberately never a synced
 * field at all (18-01 <planner_decisions> — state is locally derived).
 */

it('MergeRulesRegistry notifications _create_required is a subset of the real NOT-NULL-without-default columns', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $columns = $connection->getSchemaBuilder()->getColumns('notifications');

    /** @var list<string> $notNullWithoutDefault */
    $notNullWithoutDefault = collect($columns)
        ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
        ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
        ->pluck('name')
        ->all();

    $registry = new MergeRulesRegistry;
    $required = $registry->requiredCreateColumns('notifications');

    expect($required)->not->toBeEmpty();

    $missing = array_diff($required, $notNullWithoutDefault);
    expect($missing)->toBe([], 'every _create_required string must match a real NOT-NULL-without-default column: '.implode(', ', $missing));

    expect($required)->toEqualCanonicalizing(['id', 'user_id', 'title', 'body', 'trigger_type']);
});

it('MergeRulesRegistry notifications never registers state as a synced field', function (): void {
    $registry = new MergeRulesRegistry;

    // state is locally derived by NotificationStateMachine (18-01) — an
    // unregistered field falls through strategyFor()'s 'lww' default, but
    // it must never be dispatched as a dirtyField in practice. This test
    // pins that the registry entry itself carries no 'state' key at all.
    expect($registry->rules()['notifications'] ?? [])->not->toHaveKey('state');
});
