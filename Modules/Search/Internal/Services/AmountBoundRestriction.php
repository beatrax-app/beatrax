<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\Query\Builder;

// The reader's amount bound restated once per currency their ledger settles
// in, so the list a report row opens can carry the same rows the figure
// counted without ever comparing two denominations as bare integers.
final readonly class AmountBoundRestriction
{
    /**
     * @param  array<string, array{min: ?int, max: ?int}>  $byCurrency  the bound as it reads in each settled currency it could be stated in
     * @param  list<string>  $unpriced  settled currencies no rate reaches from the reader's own, whose rows the bound cannot test at all
     */
    private function __construct(
        private bool $stated,
        private array $byCurrency,
        private array $unpriced,
    ) {}

    public static function none(): self
    {
        return new self(false, [], []);
    }

    /**
     * @param  array<string, array{min: ?int, max: ?int}>  $byCurrency
     * @param  list<string>  $unpriced
     */
    public static function of(array $byCurrency, array $unpriced): self
    {
        return new self(true, $byCurrency, $unpriced);
    }

    // One OR branch per currency, each testing that currency's own rows
    // against that currency's own bound. An empty set is every currency left
    // out, which `whereIn` over no values says exactly.
    public function applyTo(Builder $query): void
    {
        if (! $this->stated) {
            return;
        }

        if ($this->byCurrency === []) {
            $query->whereIn('transactions.settled_currency', []);

            return;
        }

        $query->where(function (Builder $scope): void {
            foreach ($this->byCurrency as $currency => $bound) {
                $scope->orWhere(function (Builder $inCurrency) use ($currency, $bound): void {
                    self::boundIn($inCurrency, $currency, $bound);
                });
            }
        });
    }

    // Merged rather than reported on its own: a currency whose rows the bound
    // could not test and one whose rows the totals could not convert are the
    // same sentence to the reader, and the strip has one line for it.
    /**
     * @param  list<string>  $unconverted
     * @return list<string>
     */
    public function alsoUnpriced(array $unconverted): array
    {
        $codes = array_values(array_unique([...$unconverted, ...$this->unpriced]));
        sort($codes);

        return $codes;
    }

    /**
     * @param  array{min: ?int, max: ?int}  $bound
     */
    private static function boundIn(Builder $query, string $currency, array $bound): void
    {
        $query->where('transactions.settled_currency', $currency);

        if ($bound['min'] !== null) {
            $query->whereRaw('ABS(transactions.settled_amount_minor) >= ?', [$bound['min']]);
        }

        if ($bound['max'] !== null) {
            $query->whereRaw('ABS(transactions.settled_amount_minor) <= ?', [$bound['max']]);
        }
    }
}
