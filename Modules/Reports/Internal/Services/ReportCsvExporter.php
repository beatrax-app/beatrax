<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Services;

use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Support\MinorAmountFormatter;

final class ReportCsvExporter
{
    public function __construct(
        private readonly ReportAggregator $aggregator,
    ) {}

    public function export(User $user, ReportDefinition $definition): string
    {
        $writer = Writer::createFromString();

        // Mitigate spreadsheet formula injection — group labels
        // (counterparty/category/account names) are free text.
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
