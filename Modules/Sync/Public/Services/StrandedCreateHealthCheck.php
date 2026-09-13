<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Exceptions\CensusColumnMissingException;
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
        } catch (Throwable $unreadable) {
            return ['severity' => 'warning', 'message' => self::unreadable($unreadable)];
        }

        $accounted = $this->accountedFor($census['removedHere'], $census['held']);

        // Only the rows a repair could still place decide the severity. The
        // other two are stated and cost nothing, which is what lets a healthy
        // install reach ok and keeps a deliberate deletion from warning forever.
        if ($census['unplaced'] === []) {
            return ['severity' => 'ok', 'message' => $this->nothingMissing($census['checked'], $accounted)];
        }

        return ['severity' => 'warning', 'message' => $this->missing($census['unplaced'], $accounted)];
    }

    // A schema the census could not answer against names the column, because
    // the remedy is specific and the reader is at a console. Anything else is
    // the older message: the table itself is missing, which migrate also fixes.
    private static function unreadable(Throwable $unreadable): string
    {
        return $unreadable instanceof CensusColumnMissingException
            ? $unreadable->getMessage()
            : 'could not be read — run php artisan migrate';
    }

    // A count of what was examined rather than a bare "ok": a pass that reports
    // silence is unreadable, because a check that stopped looking reports the
    // same silence as one that looked at everything and found nothing.
    private function nothingMissing(int $checked, string $accounted): string
    {
        if ($checked === 0) {
            return 'the op log claims no row';
        }

        return $accounted === ''
            ? sprintf('%s the op log claims, all of them here', self::rows($checked))
            : sprintf('%s the op log claims, all of them here or accounted for: %s', self::rows($checked), $accounted);
    }

    /**
     * @param  array<string, int>  $unplaced
     */
    private function missing(array $unplaced, string $accounted): string
    {
        $line = sprintf(
            '%s a peer sent that no table here has (%s) — each arrived under an id that is either free or held by a different row; the values stay in op_log_entries and are not printed here',
            self::rows(array_sum($unplaced)),
            $this->tables($unplaced),
        );

        return $accounted === '' ? $line : $line.sprintf('. Accounted for beside them: %s', $accounted);
    }

    // Why the rest of what the log holds is not here, each said in the terms of
    // the thing that put it there. Neither is a repair anybody could run, so
    // neither is a warning -- but a row nobody can explain is worse than one
    // that costs a clause.
    /**
     * @param  array<string, int>  $removedHere
     * @param  array<string, int>  $held
     */
    private function accountedFor(array $removedHere, array $held): string
    {
        $clauses = [];

        if ($removedHere !== []) {
            $clauses[] = sprintf(
                '%s this device wrote and no longer has, with no tombstone behind them (%s)',
                self::rows(array_sum($removedHere)),
                $this->tables($removedHere),
            );
        }

        if ($held !== []) {
            $clauses[] = sprintf(
                '%s held under a verdict no later state undoes (%s)',
                self::rows(array_sum($held)),
                $this->tables($held),
            );
        }

        return implode(', ', $clauses);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function tables(array $counts): string
    {
        $named = array_slice($counts, 0, self::NAME_AT_MOST, true);
        $tables = [];

        foreach ($named as $table => $count) {
            $tables[] = sprintf('%s %d', $table, $count);
        }

        return implode(', ', $tables).(count($counts) > count($named) ? ' and more' : '');
    }

    private static function rows(int $count): string
    {
        return sprintf('%d row%s', $count, $count === 1 ? '' : 's');
    }

    /**
     * @return array{checked: int, unplaced: array<string, int>, removedHere: array<string, int>, held: array<string, int>}
     */
    private function census(): array
    {
        $checked = 0;
        $tallies = ['unplaced' => [], 'removedHere' => [], 'held' => []];

        foreach ($this->users() as $userId) {
            $census = $this->stranded->census($userId);
            $checked += $census['checked'];
            $tallies = [
                'unplaced' => self::add($tallies['unplaced'], $census['unplaced']),
                'removedHere' => self::add($tallies['removedHere'], $census['removedHere']),
                'held' => self::add($tallies['held'], $census['held']),
            ];
        }

        return [
            'checked' => $checked,
            'unplaced' => self::ordered($tallies['unplaced']),
            'removedHere' => self::ordered($tallies['removedHere']),
            'held' => self::ordered($tallies['held']),
        ];
    }

    /**
     * @param  array<string, int>  $tally
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private static function add(array $tally, array $counts): array
    {
        foreach ($counts as $table => $count) {
            $tally[$table] = ($tally[$table] ?? 0) + $count;
        }

        return $tally;
    }

    /**
     * @param  array<string, int>  $tally
     * @return array<string, int>
     */
    private static function ordered(array $tally): array
    {
        arsort($tally);

        return $tally;
    }

    // Every reader on the install, not the one at the keyboard: this runs from
    // a console with no session, and a household's second member is exactly as
    // able to lose a row as the first. Read off `users`, which is a household,
    // rather than off the log, which grows with every mutation forever.
    /**
     * @return list<int>
     */
    private function users(): array
    {
        $ids = [];

        foreach ($this->db->connection()->table('users')->orderBy('id')->pluck('id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
