<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\Query\Builder;

// The transaction ids a text query narrowed to, carried as the query that
// COMPUTES them wherever it can be: a common trigram word matches most of a
// ledger, and materialising the ids spent 18 MB of PHP ints and 25,000
// bindings on every keystroke to say something SQLite can answer in a join.
final class CandidateRestriction
{
    private ?bool $empty = null;

    /**
     * @param  Builder|list<int>  $values
     */
    private function __construct(private readonly Builder|array $values) {}

    public static function subquery(Builder $query): self
    {
        return new self($query);
    }

    /**
     * @param  list<int>  $ids
     */
    public static function ids(array $ids): self
    {
        return new self($ids);
    }

    public function applyTo(Builder $query): void
    {
        $query->whereIn('transactions.id', $this->values);
    }

    // An EXISTS probe rather than a count, and memoised, because the only
    // question asked of it is whether the text branch found anything at all —
    // and asking it must not cost what the list it replaced cost.
    public function isEmpty(): bool
    {
        if ($this->empty === null) {
            $this->empty = $this->values instanceof Builder
                ? ! (clone $this->values)->exists()
                : $this->values === [];
        }

        return $this->empty;
    }
}
