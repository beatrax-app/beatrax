<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\OpLog\OpLogRebuilder;

/*
 * The rebuild wipes replayable rows before replaying them, and the order has
 * to put children before parents. CoveredTableOrder derives that from live
 * foreign keys, but it was an OPTIONAL constructor parameter the container
 * left unresolved — so every real rebuild silently fell back to registry
 * order, which lists import_runs before transactions. The delete then hit
 * FOREIGN KEY constraint failed, the whole re-projection rolled back, and a
 * joining phone sat on "Rebuilding your history…" forever.
 */

it('resolves an FK-safe deletion order from the container, not registry order', function (): void {
    $rebuilder = app(OpLogRebuilder::class);

    $order = (function (): array {
        /** @var OpLogRebuilder $this */
        return $this->fkSafeDeletionOrder();
    })->call($rebuilder);

    $transactions = array_search('transactions', $order, true);
    $importRuns = array_search('import_runs', $order, true);

    expect($transactions)->toBeInt('transactions must be covered')
        ->and($importRuns)->toBeInt('import_runs must be covered')
        ->and($transactions)->toBeLessThan(
            $importRuns,
            'transactions.import_run_id is ON DELETE NO ACTION, so the child must be deleted first',
        );
});

it('orders every covered child ahead of the parent it references', function (): void {
    $rebuilder = app(OpLogRebuilder::class);

    $order = (function (): array {
        /** @var OpLogRebuilder $this */
        return $this->fkSafeDeletionOrder();
    })->call($rebuilder);

    $position = array_flip($order);
    $connection = app('db')->connection();
    $violations = [];

    foreach ($order as $table) {
        foreach ($connection->select('pragma foreign_key_list("'.$table.'")') as $fk) {
            $parent = is_string($fk->table ?? null) ? $fk->table : '';

            // Self-references cannot be ordered around and are handled by the
            // applier's deferred pass instead.
            if ($parent === $table || ! isset($position[$parent])) {
                continue;
            }

            if ($position[$table] > $position[$parent]) {
                $violations[] = "{$table}.{$fk->from} -> {$parent}";
            }
        }
    }

    expect($violations)->toBe([], 'parent deleted before its child: '.implode(', ', $violations));
});

it('derives the same order whether or not the collaborator is supplied', function (): void {
    $explicit = app(OpLogRebuilder::class, ['tableOrder' => app(CoveredTableOrder::class)]);
    $implicit = app(OpLogRebuilder::class);

    $read = fn (OpLogRebuilder $r): array => (function (): array {
        /** @var OpLogRebuilder $this */
        return $this->fkSafeDeletionOrder();
    })->call($r);

    expect($read($implicit))->toBe($read($explicit));
});
