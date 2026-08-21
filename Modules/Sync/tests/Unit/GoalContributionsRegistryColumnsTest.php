<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;

uses(RefreshDatabase::class);

// An attribution made on the desktop has to reach the phone, so the pivot is
// covered append-only: create and delete, with no LWW-mutable field.

it('MergeRulesRegistry goal_contributions _create_required is a subset of the real NOT-NULL-without-default columns', function (): void {
    $connection = app(DatabaseManager::class)->connection();
    $columns = $connection->getSchemaBuilder()->getColumns('goal_contributions');

    /** @var list<string> $notNullWithoutDefault */
    $notNullWithoutDefault = collect($columns)
        ->reject(static fn (array $col): bool => (bool) $col['auto_increment'])
        ->filter(static fn (array $col): bool => $col['nullable'] === false && $col['default'] === null)
        ->pluck('name')
        ->all();

    $registry = new MergeRulesRegistry;
    $required = $registry->requiredCreateColumns('goal_contributions');

    $missing = array_diff($required, $notNullWithoutDefault);
    expect($missing)->toBe([], 'every _create_required string must match a real NOT-NULL-without-default column: '.implode(', ', $missing));

    expect($required)->toEqualCanonicalizing(['goal_id', 'transaction_id']);
});

it('registers goal_contributions as a delete-wins table with no mutable field strategy', function (): void {
    $registry = new MergeRulesRegistry;

    expect($registry->isRegistered('goal_contributions'))->toBeTrue();
    expect($registry->deleteWins('goal_contributions'))->toBeTrue();

    $strategyKeys = array_values(array_filter(
        array_keys($registry->rules()['goal_contributions']),
        static fn (string $key): bool => ! str_starts_with($key, '_'),
    ));

    expect($strategyKeys)->toBe([]);
});

it('replays goals and transactions before the goal_contributions rows that reference them', function (): void {
    $order = app(CoveredTableOrder::class)->insertionOrder();

    expect($order)->toContain('goal_contributions');

    $childIndex = array_search('goal_contributions', $order, true);
    expect(array_search('goals', $order, true))->toBeLessThan($childIndex);
    expect(array_search('transactions', $order, true))->toBeLessThan($childIndex);

    expect(array_search('goal_contributions', array_reverse($order), true))
        ->toBeLessThan(array_search('goals', array_reverse($order), true));
});

it('treats no goal_contributions column as sensitive content', function (): void {
    $goalContributionColumns = array_values(array_filter(
        SensitiveFieldRegistry::columns(),
        static fn (string $column): bool => str_starts_with($column, 'goal_contributions.'),
    ));

    expect($goalContributionColumns)->toBe([]);
});
