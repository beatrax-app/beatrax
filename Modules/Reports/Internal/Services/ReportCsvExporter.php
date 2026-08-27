<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Services;

use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;

final class ReportCsvExporter
{
    public function __construct(
        private readonly ReportAggregator $aggregator,
    ) {}

    public function export(User $user, ReportDefinition $definition): string
    {
        $writer = Writer::createFromString();

        // Only the group label is escaped against formula injection: it is the
        // one free-text column, and escaping the three generated ones turned a
        // negative amount into the text "'-75.00", which no spreadsheet sums --
        // untotallable the moment the sign was restored.
        $escapeFormula = new EscapeFormula;
        $writer->addFormatter(static function (array $record) use ($escapeFormula): array {
            $record[0] = $escapeFormula->escapeRecord([$record[0]])[0];

            return $record;
        });

        // net_worth ignores the dimension entirely -- the builder even hides
        // the picker -- so a stale value in the URL used to head a column of
        // months with "Counterparty".
        $groupHeader = $definition->metric === 'net_worth' ? 'Period' : match ($definition->dimension) {
            'category' => 'Category',
            'counterparty' => 'Counterparty',
            'account' => 'Account',
            'time_bucket' => 'Period',
            default => 'Group',
        };
        $writer->insertOne([$groupHeader, 'Metric', 'Amount', 'Currency']);

        $result = $this->aggregator->run($user, $definition);
        foreach ($result->rows as $row) {
            $writer->insertOne([
                $row->groupLabel,
                $definition->metric,
                // Signed, like the screen. A `net` row is negative when more
                // left than arrived and the file carries nothing else to
                // recover the sign from, so abs() made the export unsummable
                // and put it at odds with the table it is documented to match.
                MoneyInput::toDecimalString($row->amountMinor, $row->currency),
                $row->currency,
            ]);
        }

        return $writer->toString();
    }
}
