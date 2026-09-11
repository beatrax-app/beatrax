<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge\Strategies;

use Modules\Sync\Internal\OpLog\OpLogEntry;
use UnexpectedValueException;

// A JSON object whose KEYS are independent facts. Keys merge and values are
// last-writer-wins per key, because LWW over the whole map erases every key the
// other device stamped. There is no removal, so a column where a key's ABSENCE
// is meaningful must not use this.
/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class JsonKeyUnionStrategy implements MergeStrategyInterface
{
    /**
     * @param  list<OpLogEntry>  $candidateEntries  HLC-sorted ascending.
     * @return array<string, mixed>|null The merged map, or null when no op ever carried one.
     */
    public function resolve(array $candidateEntries): mixed
    {
        $merged = [];
        $everCarriedAMap = false;

        foreach ($candidateEntries as $entry) {
            if ($entry->value === null) {
                continue;
            }

            // Later entries overwrite earlier ones key by key, which is what
            // makes this last-writer-wins per key rather than per column.
            $merged = [...$merged, ...$this->decode($entry->value)];
            $everCarriedAMap = true;
        }

        // A field no op ever gave a map to is absent, not empty. Writing {} on
        // the receiver while the origin keeps its NULL is a divergence no later
        // op can close.
        return $everCarriedAMap ? $merged : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        // Coercing a malformed value to [] would drop every key with no signal,
        // which is the failure this strategy exists to stop. The replayer
        // catches this and quarantines the op as a strategy error.
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new UnexpectedValueException('Malformed JSON-key-union value: expected an object of keys.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
