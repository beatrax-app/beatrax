<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

// notifications.id is a manually assigned sha256 string primary key rather than
// an autoincrement surrogate, and CreateRow assembly never writes the pk column
// itself, so `id` has to travel as an explicit field — otherwise a fresh device's
// insertOrIgnore silently drops the row on the id NOT NULL constraint.

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

    // state is derived locally by NotificationStateMachine and must never be
    // dispatched as a dirty field. An unregistered field would fall through to
    // strategyFor()'s lww default, so the absent key is what gets pinned.
    expect($registry->rules()['notifications'] ?? [])->not->toHaveKey('state');
});
