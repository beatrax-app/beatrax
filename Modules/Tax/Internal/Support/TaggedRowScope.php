<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @link ../../../../.docs/features/tax/tax-year-resolution.md
 */
final class TaggedRowScope
{
    public const string TAGS = 'tax_transaction_tags AS tag';

    public const string TRANSACTIONS = 'transactions AS t';

    public const string LEGS = 'transaction_splits AS ts';

    // The day the reader PAID, which for a card is the day it was swiped:
    // `booked_at` is the issuer's own booking day, a clearing artefact that is
    // a tax concept in none of the 33 countries whose corpus ships here, and a
    // 31 December swipe books on 1 January.
    /**
     * @link ../../../../.docs/features/tax/tax-year-resolution.md#which-day-the-year-is-taken-from
     */
    public const string TRANSACTION_YEAR = 'CAST(strftime(\'%Y\', t.posted_at) AS INTEGER)';

    public const string EFFECTIVE_YEAR = 'COALESCE(tag.tax_year_override, '.self::TRANSACTION_YEAR.')';

    // A whole-tx tag leaves every ts.* column NULL, which is what this falls
    // back through to reach the parent's own amount.
    public const string SETTLED_AMOUNT_MINOR = 'COALESCE(ts.settled_amount_minor, t.settled_amount_minor)';

    public static function joinLegs(QueryBuilder $query): void
    {
        $query->leftJoin(self::LEGS, 'ts.id', '=', 'tag.transaction_split_id');
    }

    // A whole-tx tag is dropped once the same transaction carries any leg-scoped
    // tag, so the two never double-count. Leg rows are always kept. Every
    // surface that reads tagged rows applies this, or it reports money and
    // counts the cockpit does not.
    public static function withoutSuperseded(QueryBuilder $query, ConnectionInterface $connection): void
    {
        $query->where(static function (QueryBuilder $q) use ($connection): void {
            $q->whereNotNull('tag.transaction_split_id')
                ->orWhereNotExists(static function (QueryBuilder $sub) use ($connection): void {
                    $sub->select($connection->raw(1))
                        ->from('tax_transaction_tags AS tag2')
                        ->whereColumn('tag2.transaction_id', 'tag.transaction_id')
                        ->whereColumn('tag2.user_id', 'tag.user_id')
                        ->whereNotNull('tag2.transaction_split_id');
                });
        });
    }
}
