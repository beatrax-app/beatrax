<?php

declare(strict_types=1);

namespace Modules\Search\Public\Support;

// The columns transaction_search_docs.search_body is composed from. A write
// that moves one leaves the index describing what the row used to say, so the
// writer owes a refresh — and the guard holding them to it reads the set here
// rather than from a second copy of it.
/**
 * @link ../../../../.docs/features/search/architecture.md
 */
final class SearchedColumns
{
    public const string TRANSACTIONS = 'transactions';

    public const string SPLITS = 'transaction_splits';

    public const string TAX_TAGS = 'tax_transaction_tags';

    /** @var array<string, list<string>> */
    private const array BY_TABLE = [
        self::TRANSACTIONS => ['counterparty_name', 'description', 'note'],
        self::SPLITS => ['note'],
        self::TAX_TAGS => ['note'],
    ];

    /**
     * @return list<string>
     */
    public static function of(string $table): array
    {
        return self::BY_TABLE[$table] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return array_keys(self::BY_TABLE);
    }

    // Asked with the column names a write just moved, so a caller names what it
    // wrote instead of restating which of those names the index reads.
    /**
     * @param  list<string>  $written
     */
    public static function touchedBy(string $table, array $written): bool
    {
        return array_intersect($written, self::of($table)) !== [];
    }
}
