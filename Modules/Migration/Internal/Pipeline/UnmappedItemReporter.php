<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use stdClass;

// Everything the promotion could not carry across, written where the preview
// summary reads it back. Its own collaborator because the three sites that
// record one — a fingerprint collision, a split that would not sum, a goal
// missing a field — differ only in the label, and drifted apart when inline.
final readonly class UnmappedItemReporter
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, string>  $payeeNameMap
     */
    public function transactionNotCarried(int $runId, User $user, stdClass $row, array $payeeNameMap, CopyLine $reason): void
    {
        $this->record($runId, $user, self::toString($row->source_external_id), self::transactionLabel($row, $payeeNameMap), $reason);
    }

    public function goalNotCarried(int $runId, User $user, string $categoryExternalId, string $name, CopyLine $reason): void
    {
        $this->record($runId, $user, $categoryExternalId, CopyLine::of('migration::unmapped.label.goal', ['name' => $name]), $reason);
    }

    // Neither YNAB export carries a description at all, so naming a lost row by
    // that column alone printed "(no description)" for every one of them and
    // left the reader unable to tell which transaction the list was about. The
    // date and the amount ride as values, so both follow whoever reads them.
    /**
     * @param  array<string, string>  $payeeNameMap
     */
    public static function transactionLabel(stdClass $row, array $payeeNameMap): CopyLine
    {
        $payeeExternalId = $row->payee_source_external_id;
        $payeeName = is_string($payeeExternalId) ? ($payeeNameMap[$payeeExternalId] ?? null) : null;
        $description = $row->description !== null ? self::toString($row->description) : null;

        $identifier = match (true) {
            $payeeName !== null && trim($payeeName) !== '' => $payeeName,
            $description !== null && trim($description) !== '' => $description,
            default => CopyParam::line('migration::unmapped.label.transaction_unnamed'),
        };

        return CopyLine::of('migration::unmapped.label.transaction', [
            'name' => $identifier,
            'date' => CopyParam::dateWithYear(CarbonImmutable::parse(self::toString($row->posted_at))),
            'amount' => CopyParam::money(self::toInt($row->amount_minor), self::toString($row->currency)),
        ]);
    }

    private function record(int $runId, User $user, string $sourceExternalId, CopyLine $label, CopyLine $reason): void
    {
        $this->db->connection()->table('migration_staging_unmapped_items')->insert([
            'user_id' => $user->id,
            'migration_run_id' => $runId,
            'item_type' => UnmappedItemType::Extra->value,
            'source_external_id' => $sourceExternalId,
            'display_label' => StoredCopy::of($label),
            'reason' => StoredCopy::of($reason),
        ]);
    }
}
