<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

// The ids a JSON column carries. A foreign key describes a column, and a JSON
// column is one opaque text value whatever it holds, so every mechanism that
// walks references by column is blind to these — the merge layer's alias
// translation was, and so was the counterparty fold's repoint list.

// Declared once and read by both, because the two failures are one shape: an
// id inside a JSON value that nobody rewrote when the row it names moved.
/**
 * @link ../../../../.docs/features/sync/op-log-merge-rules.md#references-that-live-inside-a-json-value
 */
final class JsonRowReferences
{
    /**
     * @var array<string, array<string, array<string, string>>> table => column => (path => the table it names)
     */
    private const array REFERENCES = [
        'saved_reports' => [
            'definition' => [
                'accounts.*' => 'accounts',
                'categories.*' => 'categories',
                'counterparties.*' => 'counterparties',
            ],
        ],
        // `rule_id` is deliberately absent: categorization_rules is device-local,
        // so no create for one ever arrives, no alias can exist, and declaring
        // it would read as coverage where none is possible.
        'transactions' => [
            'auto_category_provenance' => [
                'category_id' => 'categories',
                'memory_id' => 'merchant_memories',
            ],
            'enriched_from' => ['*.import_run_id' => 'import_runs'],
        ],
        'user_preferences' => [
            'calendar_entries_accounts' => ['*' => 'accounts'],
            'calendar_balance_accounts' => ['*' => 'accounts'],
        ],
    ];

    // A path is dot-separated and `*` stands for every element of a list, so
    // one notation covers a bare list of ids, a keyed list, and an id nested
    // one element deep inside a list of objects.
    /**
     * @return array<string, array<string, string>> column => (path => the table it names)
     */
    public function columnsFor(string $table): array
    {
        return self::REFERENCES[$table] ?? [];
    }

    // Every table a declared site sits on. A repoint writes and announces under
    // one of these and under nothing else, which is what lets a guard reading a
    // dynamically-named capture resolve it rather than pin it as unreadable.
    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys(self::REFERENCES);
    }

    // Every declared site that names $target, for a caller that starts from the
    // row being removed rather than from the row being written. The counterparty
    // fold repoints through this instead of naming the columns it remembers.
    /**
     * @return list<array{table: string, column: string, path: string}>
     */
    public function sitesNaming(string $target): array
    {
        $sites = [];

        foreach (self::REFERENCES as $table => $columns) {
            foreach ($columns as $column => $paths) {
                foreach ($paths as $path => $named) {
                    if ($named === $target) {
                        $sites[] = ['table' => $table, 'column' => $column, 'path' => $path];
                    }
                }
            }
        }

        return $sites;
    }

    // The value with every id the paths reach passed through $resolve, handed
    // back in the shape it arrived in: a captured row carries the stored JSON
    // text, a live write carries the array behind it. Anything that will not
    // decode is left alone.
    /**
     * @param  array<string, string>  $paths  path => the table it names
     * @param  callable(string, int|string): (int|string)  $resolve
     */
    public function rewrite(mixed $value, array $paths, callable $resolve): mixed
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            return $value;
        }

        foreach ($paths as $path => $named) {
            $decoded = $this->rewriteAtPath($decoded, explode('.', $path), $named, $resolve);
        }

        if (! is_string($value)) {
            return $decoded;
        }

        $encoded = json_encode($decoded);

        return is_string($encoded) ? $encoded : $value;
    }

    // Walks one path into the decoded value and rewrites what it reaches. An
    // empty segment list is the leaf, `*` is every element, and a key the value
    // does not carry rewrites nothing.
    /**
     * @param  list<string>  $segments
     * @param  callable(string, int|string): (int|string)  $resolve
     */
    private function rewriteAtPath(mixed $node, array $segments, string $named, callable $resolve): mixed
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return $this->rewriteLeaf($node, $named, $resolve);
        }

        if (! is_array($node)) {
            return $node;
        }

        if ($segment === '*') {
            $keys = array_keys($node);
        } else {
            $keys = array_key_exists($segment, $node) ? [$segment] : [];
        }

        foreach ($keys as $key) {
            $node[$key] = $this->rewriteAtPath($node[$key], $segments, $named, $resolve);
        }

        return $node;
    }

    // Anything that is not an id — a null clearing the column, a bool, an empty
    // string — is handed back without asking the resolver about it.
    /**
     * @param  callable(string, int|string): (int|string)  $resolve
     */
    private function rewriteLeaf(mixed $value, string $named, callable $resolve): mixed
    {
        if ((! is_int($value) && ! is_string($value)) || $value === '') {
            return $value;
        }

        return $resolve($named, $value);
    }
}
