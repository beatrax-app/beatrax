<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Core\Public\Support\SafeDate;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\PeriodQuery;

// The old re-key mapped a stored row by the old period's FIRST instant, which
// under any later start day lands one period earlier -- below the genesis an
// upgrader's fold walks from, where no read and no month-back nav reaches it
// again. This lifts exactly those rows back onto genesis.
/**
 * @link ../../../../.docs/features/budgets/moving-the-budget-month.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        /** @var PeriodQuery $periods */
        $periods = Container::getInstance()->make(PeriodQuery::class);
        /** @var BaseCurrency $baseCurrency */
        $baseCurrency = Container::getInstance()->make(BaseCurrency::class);
        $connection = $this->db()->connection($this->getConnection());

        $users = $connection->table('users')
            ->whereNotNull('envelope_activated_at')
            ->get(['id', 'period_start_day', 'envelope_activated_at', 'base_currency']);

        foreach ($users as $user) {
            $activatedAt = SafeDate::parseOrNull(is_string($user->envelope_activated_at) ? $user->envelope_activated_at : '');
            if (! $activatedAt instanceof CarbonImmutable) {
                continue;
            }

            $userId = (int) $user->id;
            $genesis = $periods->containingForDay((int) $user->period_start_day, $activatedAt)->start;

            // The old mapping could only ever be one period out: the stored key
            // and the anchor sat in the same old period, so the new windows they
            // fall in are at most one apart. Anything earlier was already below
            // genesis before the move and is left where the reader put it.
            $floor = $genesis->subMonthNoOverflow()->toDateString();
            $genesisKey = $genesis->toDateString();

            // forUser()'s own rule, off the raw row: a migration holds no
            // reader, and the column was added nullable with no backfill.
            $chosen = $user->base_currency;
            $reportingCurrency = is_string($chosen) && $chosen !== ''
                ? $chosen
                : $baseCurrency->installDefault();

            $this->liftAssignments($connection, $userId, $floor, $genesisKey, $reportingCurrency);

            $connection->table('envelope_moves')
                ->where('user_id', $userId)
                ->where('period_start', '>=', $floor)
                ->where('period_start', '<', $genesisKey)
                ->update(['period_start' => $genesisKey]);
        }
    }

    public function down(): void
    {
        // Forward-only: the day the rows were stranded under was never recorded,
        // so there is nothing to put them back to.
    }

    // (user_id, category_id, period_start) is UNIQUE, so a stranded row whose
    // envelope already has a genesis row merges into it rather than colliding.
    private function liftAssignments(
        Connection $connection,
        int $userId,
        string $floor,
        string $genesisKey,
        string $reportingCurrency,
    ): void {
        $stranded = $connection->table('envelope_assignments')
            ->where('user_id', $userId)
            ->where('period_start', '>=', $floor)
            ->where('period_start', '<', $genesisKey)
            ->get(['id', 'category_id', 'assigned_minor', 'currency']);

        foreach ($stranded as $row) {
            /** @var stdClass|null $existing */
            $existing = $connection->table('envelope_assignments')
                ->where('user_id', $userId)
                ->where('category_id', $row->category_id)
                ->where('period_start', $genesisKey)
                ->first(['id', 'assigned_minor', 'currency']);

            if ($existing === null) {
                $connection->table('envelope_assignments')
                    ->where('id', $row->id)
                    ->update(['period_start' => $genesisKey]);

                continue;
            }

            $connection->table('envelope_assignments')
                ->where('id', $existing->id)
                ->update($this->merged($existing, $row, $reportingCurrency));
            $connection->table('envelope_assignments')->where('id', $row->id)->delete();
        }
    }

    // EnvelopeWriter stamps the reader's base currency at write time, so two
    // months either side of a currency change hold different codes and adding
    // their minor units invents the difference. EnvelopePeriodRekeyer::totalled()
    // merges the same pair by the same rule, and this is the same merge.
    /**
     * @return array{assigned_minor: int, currency: string}
     *
     * @link ../../../../.docs/features/budgets/moving-the-budget-month.md#the-invariant-no-row-lands-below-genesis
     */
    private function merged(stdClass $existing, stdClass $stranded, string $reportingCurrency): array
    {
        $existingCurrency = is_string($existing->currency) ? $existing->currency : '';
        $strandedCurrency = is_string($stranded->currency) ? $stranded->currency : '';
        $summed = (int) $existing->assigned_minor + (int) $stranded->assigned_minor;

        if ($existingCurrency === $strandedCurrency) {
            return ['assigned_minor' => $summed, 'currency' => $existingCurrency];
        }

        /** @var CrossCurrencyTotal $fx */
        $fx = Container::getInstance()->make(CrossCurrencyTotal::class);
        $converted = $fx->of([
            $existingCurrency => (int) $existing->assigned_minor,
            $strandedCurrency => (int) $stranded->assigned_minor,
        ], $reportingCurrency);

        // A pair the rate table cannot price whole keeps the raw sum, as the
        // rekeyer does: dropping the half with no rate would delete stored
        // money that comes back the day a rate arrives.
        return $converted->unconverted === []
            ? ['assigned_minor' => $converted->minor, 'currency' => $reportingCurrency]
            : ['assigned_minor' => $summed, 'currency' => $existingCurrency];
    }
};
