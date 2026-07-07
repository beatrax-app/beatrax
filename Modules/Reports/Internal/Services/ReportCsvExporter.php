<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Services;

use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Support\MinorAmountFormatter;
use Modules\Reports\Public\Dto\ReportDefinition;

/**
 * Streams a report's aggregated rows as CSV (Req 11).
 *
 * Mirrors `Modules\Tax\Internal\Services\TaxCsvExporter`'s structure
 * (999.6-PATTERNS.md): `EscapeFormula` on every free-text column (WR-08,
 * T-999.6-18), and reads exclusively through `ReportAggregator::run()` so
 * the CSV can never disagree with the on-screen table/chart — every
 * consumer of a `ReportDefinition` composes the same aggregator.
 *
 * The header's first (group) column reflects the driving definition's
 * `dimension` — 'Category' | 'Counterparty' | 'Account' | 'Month' — never a
 * generic 'group' literal (Req 11).
 *
 * IN-01: the "Amount" column is formatted via `MinorAmountFormatter` (pure
 * integer arithmetic), never plain `float` division — CLAUDE.md "What NOT
 * to Use" explicitly forbids `/100`-style float division on money.
 */
final class ReportCsvExporter
{
    public function __construct(
        private readonly ReportAggregator $aggregator,
    ) {}

    public function export(User $user, ReportDefinition $definition): string
    {
        $writer = Writer::createFromString();

        // WR-08 / T-999.6-18: mitigate spreadsheet formula injection —
        // group labels (counterparty/category/account names) are free text.
        $writer->addFormatter(new EscapeFormula);

        $groupHeader = match ($definition->dimension) {
            'category' => 'Category',
            'counterparty' => 'Counterparty',
            'account' => 'Account',
            'time_bucket' => 'Month',
            default => 'Group',
        };
        $writer->insertOne([$groupHeader, 'Metric', 'Amount', 'Currency']);

        $result = $this->aggregator->run($user, $definition);
        foreach ($result->rows as $row) {
            $writer->insertOne([
                $row->groupLabel,
                $definition->metric,
                MinorAmountFormatter::toUnsignedDecimalString($row->amountMinor),
                $row->currency,
            ]);
        }

        return $writer->toString();
    }
}
