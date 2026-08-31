<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Services;

use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGroupHeading;

final readonly class ReportCsvExporter
{
    public function __construct(
        private ReportAggregator $aggregator,
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

        $result = $this->aggregator->run($user, $definition);

        // The same rows the screen renders: with comparison on that is the
        // union of both windows' groups. Exporting ->rows dropped every group
        // that had fallen to zero -- rows the reader could see -- and the whole
        // column they had turned comparison on to get.
        $comparing = $definition->compare && $result->comparisonRows !== null;
        $rows = $comparing ? $result->comparisonRows ?? [] : $result->rows;

        $header = [
            ReportGroupHeading::for($definition->metric, $definition->dimension)->value,
            'Metric',
            'Amount',
            'Currency',
        ];
        $writer->insertOne($comparing ? [...$header, 'Delta'] : $header);

        foreach ($rows as $row) {
            $record = [
                $row->groupLabel,
                $definition->metric,
                // Signed, like the screen. A `net` row is negative when more
                // left than arrived and the file carries nothing else to
                // recover the sign from, so abs() made the export unsummable
                // and put it at odds with the table it is documented to match.
                MoneyInput::toDecimalString($row->amountMinor, $row->currency),
                $row->currency,
            ];

            // Empty, never "0.00", for a row the other window has no
            // counterpart for -- the em dash the table prints in that cell.
            $delta = $row->deltaMinor === null
                ? ''
                : MoneyInput::toDecimalString($row->deltaMinor, $row->currency);

            $writer->insertOne($comparing ? [...$record, $delta] : $record);
        }

        return $writer->toString();
    }
}
