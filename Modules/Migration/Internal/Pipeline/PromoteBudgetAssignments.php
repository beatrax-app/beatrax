<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Migration\Internal\Enums\MigrationEntityType;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use Modules\Migration\Internal\Services\SourceMapWriter;
use Modules\Migration\Internal\ValueObjects\SourceMapKey;
use stdClass;

// The staged budget history, written into the reader's envelopes. Its own
// collaborator because a budget cell is the one promoted entity the reader may
// have edited between two imports, so most of the work here is deciding which
// cells the export is still allowed to speak for.
final readonly class PromoteBudgetAssignments
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private SourceMapWriter $sourceMapWriter,
        private EnvelopeWriter $envelopeWriter,
        private PeriodQuery $periods,
        private BaseCurrency $baseCurrency,
    ) {}

    /**
     * @param  array<string, int>  $categoryIdMap
     * @param  list<string>  $skipKeys  `{categoryExternalId}|{period_start}` composite keys to leave
     *                                  untouched (reconciliation conflicts).
     * @return int the months this actually wrote, which is what the results screen reports as imported
     */
    public function promote(int $runId, User $user, string $sourceProduct, array $categoryIdMap, array $skipKeys = []): int
    {
        $rows = $this->db->connection()->table('migration_staging_budget_assignments')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $runId)
            ->get();

        $envelopeCurrency = $this->baseCurrency->forUser($user);

        /** @var array<string, int> $foreignMonths */
        $foreignMonths = [];

        /** @var array<string, true> $writtenMonths */
        $writtenMonths = [];

        /** @var stdClass $row */
        foreach ($rows as $row) {
            $categoryExternalId = self::toString($row->source_category_external_id);
            $categoryId = $categoryIdMap[$categoryExternalId] ?? null;
            if ($categoryId === null) {
                continue;
            }

            // An envelope carries the reader's own currency and the fold never
            // converts, so writing a foreign-currency budget here would relabel
            // it one for one -- a $500 plan read as a €500 one.
            $rowCurrency = self::toString($row->currency);
            if ($rowCurrency !== '' && $rowCurrency !== $envelopeCurrency) {
                $foreignMonths[$rowCurrency] = ($foreignMonths[$rowCurrency] ?? 0) + 1;

                continue;
            }

            $periodStart = CarbonImmutable::parse(self::toString($row->period_start));

            // The source file's month boundary is the map key and stays raw; the
            // stored row is keyed to the reader's own period, which is where the
            // writer put it.
            $externalId = $categoryExternalId.'|'.$periodStart->toDateString();

            if (in_array($externalId, $skipKeys, true)) {
                continue;
            }

            $minor = self::toInt($row->budgeted_minor);
            $key = new SourceMapKey($sourceProduct, MigrationEntityType::BudgetAssignment->value, $externalId);
            $mappedId = $this->sourceMapWriter->resolve($user, $key);

            if ($this->leavesTheCellAsItFoundIt($user, $key, $minor, $mappedId)) {
                continue;
            }

            $this->writeAssignment($user, $categoryId, $periodStart, $key, $minor, $mappedId);
            $writtenMonths[$periodStart->toDateString()] = true;
        }

        $this->recordForeignBudgetCurrencies($runId, $user, $envelopeCurrency, $foreignMonths);

        return count($writtenMonths);
    }

    /**
     * @param  int|null  $mappedId  the assignment a previous import of this cell mapped, if any
     */
    private function leavesTheCellAsItFoundIt(User $user, SourceMapKey $key, int $minor, ?int $mappedId): bool
    {
        // A zero for a cell this import has never mapped is the export
        // having no budget there, not an instruction to delete one the
        // reader typed in themselves.
        if ($minor === 0 && $mappedId === null) {
            return true;
        }

        // The export has not moved since it was last imported, so there is
        // nothing to promote — re-applying it would overwrite whatever the
        // reader did to this cell in between, including a kept-local one.
        $baseline = $this->sourceMapWriter->baselineFor($user, $key, 'budgeted_minor');

        return $baseline !== null && (int) $baseline === $minor;
    }

    private function writeAssignment(User $user, int $categoryId, CarbonImmutable $periodStart, SourceMapKey $key, int $minor, ?int $mappedId): void
    {
        $this->envelopeWriter->setAssigned($user, $categoryId, $periodStart, $minor);

        $assignmentId = $this->db->connection()->table('envelope_assignments')
            ->where('user_id', $user->id)
            ->where('category_id', $categoryId)
            ->where('period_start', $this->periods->containingForUser($user, $periodStart)->start->toDateString())
            ->value('id');

        // A zero leaves no row behind, so the cell stays addressable through
        // the id its last non-zero import mapped.
        $beatraxId = $assignmentId !== null ? self::toInt($assignmentId) : $mappedId;

        if ($beatraxId !== null) {
            $this->sourceMapWriter->record(
                $user,
                $key,
                'envelope_assignment',
                $beatraxId,
                ['budgeted_minor' => (string) $minor],
            );
        }
    }

    /**
     * @param  array<string, int>  $foreignMonths  source currency => rows left unwritten
     */
    private function recordForeignBudgetCurrencies(int $runId, User $user, string $envelopeCurrency, array $foreignMonths): void
    {
        foreach ($foreignMonths as $currency => $count) {
            $this->db->connection()->table('migration_staging_unmapped_items')->insert([
                'user_id' => $user->id,
                'migration_run_id' => $runId,
                'item_type' => UnmappedItemType::Extra->value,
                'source_external_id' => 'budget_currency|'.$currency,
                'display_label' => StoredCopy::of(CopyLine::of('migration::unmapped.label.budget_history', ['currency' => $currency])),
                'reason' => StoredCopy::of(CopyLine::plural('migration::unmapped.reason.budget_currency_mismatch', $count, [
                    'envelope' => $envelopeCurrency,
                    'source' => $currency,
                ])),
            ]);
        }
    }
}
