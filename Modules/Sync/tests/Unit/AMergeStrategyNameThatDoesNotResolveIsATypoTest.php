<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\MergeStrategy;

// strategyFor() reads a strategy name out of this repository's own registry and
// falls back to last-writer-wins when it does not resolve. Absent meaning Lww is
// the design — a field names a strategy only where it is one of the two that are
// not one. A name that is PRESENT and does not resolve is a different thing: a
// typo in the registry, which the fallback merges as a last-writer value.

// Only two fields in the whole tree are not Lww, and both lose real data under
// that fallback. A g-counter merged as Lww takes one device's total instead of
// the sum, and an or-set takes one device's members instead of the union — with
// both devices reporting a clean merge.
/**
 * @return list<array{table: string, field: string, strategy: string}>
 */
function declaredMergeStrategies(): array
{
    $declared = [];

    foreach (app(MergeRulesRegistry::class)->rules() as $table => $fields) {
        foreach ($fields as $field => $config) {
            if (! is_array($config) || ! is_string($config['strategy'] ?? null)) {
                continue;
            }

            $declared[] = ['table' => (string) $table, 'field' => (string) $field, 'strategy' => $config['strategy']];
        }
    }

    return $declared;
}

it('names no merge strategy the enum does not have', function (): void {
    $unresolved = [];

    foreach (declaredMergeStrategies() as $entry) {
        if (MergeStrategy::tryFrom($entry['strategy']) === null) {
            $unresolved[] = "{$entry['table']}.{$entry['field']} names '{$entry['strategy']}', which is not a MergeStrategy — strategyFor() merges it as last-writer-wins.";
        }
    }

    expect($unresolved)->toBe([]);
});

// Pinned rather than counted: a line deleted from the registry leaves no trace
// at all, because the field then merges as Lww exactly as an unlisted one does.
it('keeps the two fields that are not last-writer-wins', function (): void {
    $registry = app(MergeRulesRegistry::class);

    expect($registry->strategyFor('merchant_memories', 'occurrence_count'))->toBe(MergeStrategy::GCounter)
        ->and($registry->strategyFor('merchant_aliases', 'merged_from'))->toBe(MergeStrategy::OrSet)
        ->and(declaredMergeStrategies())->toHaveCount(2);
});
