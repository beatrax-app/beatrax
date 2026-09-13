<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Merge\StrandedCreates;
use Throwable;

// Lives in Public so Core's DoctorCommand reaches it without crossing into
// Sync Internal, and returns plain values so that command builds the row.
// Mirrors SplitSumHealthCheck, which asks the other question nothing else asks
// out loud: what the database is missing that the log can still prove it had.
/**
 * @link ../../../../.docs/features/sync/architecture.md#rows-the-log-holds-and-the-table-does-not
 */
final readonly class StrandedCreateHealthCheck
{
    // The row names counts and table names and nothing else. What is stranded
    // is the reader's own ledger -- a counterparty is the shop they bought
    // from -- and a console line is copied into bug reports.
    private const int NAME_AT_MOST = 6;

    public function __construct(
        private DatabaseManager $db,
        private StrandedCreates $stranded,
    ) {}

    public function label(): string
    {
        return 'rows the log still holds';
    }

    /**
     * @return 'ok'|'warning'
     */
    public function severity(): string
    {
        return $this->result()['severity'];
    }

    public function message(): string
    {
        return $this->result()['message'];
    }

    /**
     * @return array{severity: 'ok'|'warning', message: string}
     */
    private function result(): array
    {
        try {
            $census = $this->census();
        } catch (Throwable) {
            return ['severity' => 'warning', 'message' => 'could not be read — run php artisan migrate'];
        }

        if ($census['stranded'] === []) {
            return ['severity' => 'ok', 'message' => $this->nothingMissing($census['checked'])];
        }

        return ['severity' => 'warning', 'message' => $this->missing($census['stranded'])];
    }

    // A count of what was examined rather than a bare "ok": a pass that reports
    // silence is unreadable, because a check that stopped looking reports the
    // same silence as one that looked at everything and found nothing.
    private function nothingMissing(int $checked): string
    {
        return $checked === 0
            ? 'the op log claims no row'
            : sprintf('%s the op log claims, all of them here', self::rows($checked));
    }

    /**
     * @param  array<string, int>  $stranded
     */
    private function missing(array $stranded): string
    {
        $named = array_slice($stranded, 0, self::NAME_AT_MOST, true);
        $tables = [];

        foreach ($named as $table => $count) {
            $tables[] = sprintf('%s %d', $table, $count);
        }

        $more = count($stranded) > count($named) ? ' and more' : '';

        return sprintf(
            '%s the op log holds and no table has (%s%s) — each arrived under an id that is either free or held by a different row; the values stay in op_log_entries and are not printed here',
            self::rows(array_sum($stranded)),
            implode(', ', $tables),
            $more,
        );
    }

    private static function rows(int $count): string
    {
        return sprintf('%d row%s', $count, $count === 1 ? '' : 's');
    }

    /**
     * @return array{checked: int, stranded: array<string, int>}
     */
    private function census(): array
    {
        $checked = 0;
        $stranded = [];

        foreach ($this->users() as $userId) {
            $census = $this->stranded->census($userId);
            $checked += $census['checked'];

            foreach ($census['stranded'] as $table => $count) {
                $stranded[$table] = ($stranded[$table] ?? 0) + $count;
            }
        }

        arsort($stranded);

        return ['checked' => $checked, 'stranded' => $stranded];
    }

    // Every reader the log carries ops for, not the one at the keyboard: this
    // runs from a console with no session, and a household's second member is
    // exactly as able to lose a row as the first.
    /**
     * @return list<int>
     */
    private function users(): array
    {
        $ids = [];

        foreach ($this->db->connection()->table('op_log_entries')->distinct()->orderBy('user_id')->pluck('user_id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
