<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\FX\Public\Services\CrossCurrencyBound;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Search\Public\Dto\SearchFilters;

// The report figure a filtered list opens from counted the reader's bound in
// every currency their ledger settles in, so the list restates it the same way
// rather than narrowing to the reader's own -- a row the list could not account
// for is the report making a claim nothing beneath it substantiates.
/**
 * @link ../../../../.docs/features/ledger/minor-units-and-zero-decimal-currencies.md#the-other-half-comparing-two-denominations-as-bare-integers
 */
final readonly class AmountBoundResolver
{
    public function __construct(
        private DatabaseManager $db,
        private CrossCurrencyBound $bound,
    ) {}

    // Resolved once per search rather than inside applyFilters(): the candidate
    // pass and the row query both apply it, and one of them running against a
    // differently-priced bound is the disagreement this exists to close.
    public function forUser(User $user, SearchFilters $filters, string $readerCurrency): AmountBoundRestriction
    {
        // A filter that will not parse is dropped rather than widened to zero:
        // "> €0" is every row, which is not what the typist asked for.
        $minMinor = $filters->amountMin === null ? null : MoneyInput::tryToMinor($filters->amountMin, $readerCurrency);
        $maxMinor = $filters->amountMax === null ? null : MoneyInput::tryToMinor($filters->amountMax, $readerCurrency);

        if ($minMinor === null && $maxMinor === null) {
            return AmountBoundRestriction::none();
        }

        $byCurrency = [];
        $unpriced = [];

        foreach ($this->settledCurrencies($user) as $currency) {
            $restated = $this->bound->restate($minMinor, $maxMinor, $readerCurrency, $currency);

            if ($restated === null) {
                $unpriced[] = $currency;

                continue;
            }

            $byCurrency[$currency] = $restated;
        }

        return AmountBoundRestriction::of($byCurrency, $unpriced);
    }

    // The reader's whole ledger, not the currencies inside the filtered window:
    // a currency carrying no matching row contributes no branch's worth of
    // rows, while one discovered from a narrower scope than the query it bounds
    // would drop rows the query can still reach.
    /**
     * @return list<string>
     */
    private function settledCurrencies(User $user): array
    {
        $values = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereNotNull('settled_currency')
            ->groupBy('settled_currency')
            ->orderBy('settled_currency')
            ->pluck('settled_currency');

        $currencies = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $currencies[] = $value;
            }
        }

        return $currencies;
    }
}
