<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Enums\PotAlertKind;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Pots\Public\Enums\PotMovementKind;
use Modules\Pots\Public\Enums\PotStatus;

// The cutover archives every category-linked pot and hands its balance back to
// the account's unallocated pool, which to the reader is money they had set
// aside sitting loose in an account for a reason nothing on screen explains.
/**
 * @link ../../../../.docs/features/pots/category-link-retirement.md
 */
final readonly class RecordCategoryPotRetirementAlert
{
    use CoercesScalars;

    private const string COPY_KEY = 'core::alerts.messages.pots_category_link_retired';

    public function __construct(
        private DatabaseManager $db,
        private SystemAlertWriter $alerts,
    ) {}

    // One row per currency. Two currencies have no sum, and a sentence that
    // named one of them over a total of both would be the wrong number rather
    // than a missing one.
    public function __invoke(int $userId): void
    {
        foreach ($this->releasedByCurrency($userId) as $released) {
            $this->raise($userId, $released);
        }
    }

    /**
     * @return list<array{currency: string, minor: int, pots: int}>
     */
    private function releasedByCurrency(int $userId): array
    {
        $connection = $this->db->connection();

        // A pot that was already empty releases nothing and writes no movement
        // at all, so it is absent here — which is the population this notice
        // wants: no money moved is nothing to raise an amber banner over.
        $rows = $connection->table('pot_movements')
            ->join('pots', 'pots.id', '=', 'pot_movements.pot_id')
            ->where('pots.user_id', $userId)
            ->where('pots.status', PotStatus::Archived->value)
            ->whereNotNull('pots.category_id')
            ->where('pot_movements.kind', PotMovementKind::ReleasedOnArchive->value)
            ->groupBy('pot_movements.currency')
            ->orderBy('pot_movements.currency')
            ->get([
                'pot_movements.currency as currency',
                $connection->raw('SUM(pot_movements.amount_minor) as released_minor'),
                $connection->raw('COUNT(DISTINCT pot_movements.pot_id) as pot_count'),
            ]);

        $released = [];
        foreach ($rows as $row) {
            $line = $this->releasedLine((array) $row);
            if ($line !== null) {
                $released[] = $line;
            }
        }

        return $released;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array{currency: string, minor: int, pots: int}|null
     */
    private function releasedLine(array $row): ?array
    {
        $currency = self::toString($row['currency'] ?? null);

        // The release is stored as a negative movement, so the figure the
        // reader is owed is its inverse.
        $minor = -self::toInt($row['released_minor'] ?? null);
        $pots = self::toInt($row['pot_count'] ?? null);

        if ($currency === '' || $minor <= 0 || $pots <= 0) {
            return null;
        }

        return ['currency' => $currency, 'minor' => $minor, 'pots' => $pots];
    }

    /**
     * @param  array{currency: string, minor: int, pots: int}  $released
     */
    private function raise(int $userId, array $released): void
    {
        $kind = PotAlertKind::CategoryLinkRetired;

        // The line is stored as a spec plus a written fallback, so the reader
        // sees it in their own language and their own money format rather than
        // in whichever the migration happened to run in.
        $line = CopyLine::plural(self::COPY_KEY, $released['pots'], [
            'amount' => CopyParam::money($released['minor'], $released['currency']),
        ]);

        // The currency is what makes one of these rows distinct from the next:
        // every device that upgrades walks the same movements and reaches the
        // same figures, so both land on one row per currency.
        $this->alerts->raiseDerivedForUser(
            $userId,
            $kind->value,
            $kind->severity()->value,
            $line->sentence(),
            ['currency' => $released['currency']],
            StoredCopy::inParams($line),
        );
    }
}
