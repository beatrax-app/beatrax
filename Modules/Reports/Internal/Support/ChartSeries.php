<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ReportViz;

// What a chart can actually draw out of a report's rows, and what it therefore
// leaves to the table underneath. Both narrowings are omissions, so both are
// carried out of here to be said on the page rather than applied quietly.
final readonly class ChartSeries
{
    /**
     * @param  list<ReportResultRow>  $rows  the drawable rows, all in $currency
     * @param  list<string>  $drilldownUrls  parallel to $rows
     * @param  list<string>  $otherCurrencies  present in the report and not on this axis
     * @param  int  $undrawnMinor  signed sum, in $currency, of the rows moving against the total — a ring cannot draw them
     */
    private function __construct(
        public array $rows,
        public array $drilldownUrls,
        public string $currency,
        public array $otherCurrencies,
        public int $undrawnMinor,
    ) {}

    /**
     * @param  list<ReportResultRow>  $rows
     * @param  list<string>  $drilldownUrls  parallel to $rows
     */
    public static function for(string $viz, array $rows, array $drilldownUrls, string $currency, int $totalMinor): self
    {
        $positions = ChartAmount::positionsInCurrency($rows, $currency);

        $otherCurrencies = [];
        foreach ($rows as $row) {
            if ($row->currency !== $currency && ! in_array($row->currency, $otherCurrencies, true)) {
                $otherCurrencies[] = $row->currency;
            }
        }
        sort($otherCurrencies);

        $undrawnMinor = 0;
        if ($viz === ReportViz::Donut->value) {
            $towards = ChartAmount::positionsTowards($rows, $totalMinor);
            foreach (array_diff($positions, $towards) as $position) {
                $undrawnMinor += $rows[$position]->amountMinor;
            }
            $positions = array_values(array_intersect($positions, $towards));
        }

        return new self(
            rows: array_map(static fn (int $position): ReportResultRow => $rows[$position], $positions),
            drilldownUrls: array_map(static fn (int $position): string => $drilldownUrls[$position] ?? '#', $positions),
            currency: $currency,
            otherCurrencies: $otherCurrencies,
            undrawnMinor: $undrawnMinor,
        );
    }
}
