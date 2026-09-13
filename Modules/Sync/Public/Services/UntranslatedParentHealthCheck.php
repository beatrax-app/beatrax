<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Merge\UntranslatedParentIds;
use Modules\Sync\Internal\Merge\UntranslatedParentVerdict;
use Throwable;

// The question nothing asked. A foreign key cannot: `counterparty_id` carries
// none by design, and a key passes an id naming the WRONG row anyway. The
// stranded-create census cannot either -- it asks whether the parent row is
// here, never what points at it, and four of the nineteen rows ARE here.

// In Public so Core's DoctorCommand reaches it without crossing into Internal.
/**
 * @link ../../../../.docs/features/sync/architecture.md#an-id-that-crossed-before-its-alias-existed
 */
final readonly class UntranslatedParentHealthCheck
{
    private const int NAME_AT_MOST = 6;

    public function __construct(
        private DatabaseManager $db,
        private UntranslatedParentIds $parents,
    ) {}

    public function label(): string
    {
        return 'ids a peer sent';
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
            return ['severity' => 'warning', 'message' => 'could not be read — run php artisan migrate'];
        }

        // Added per column, never unioned: both tallies are keyed by
        // table.column, and `+` on two arrays sharing a key keeps the left
        // one's value — which read five where thirty-two ids were wrong.
        $actionable = self::added($census['misfiled'], $census['unplaceable']);

        // Only the ids something here could still repoint decide the severity.
        // An id whose meaning is written in the PEER's alias table and nowhere
        // else cannot be cleared from this device, and a warning nothing can
        // clear is one a reader learns to read past.
        if ($actionable === []) {
            return ['severity' => 'ok', 'message' => $this->allAgree($census['checked'], $census['unspoken'])];
        }

        return ['severity' => 'warning', 'message' => $this->disagree($census, $actionable)];
    }

    /**
     * @param  array<string, int>  $unspoken
     */
    private function allAgree(int $checked, array $unspoken): string
    {
        if ($checked === 0) {
            return 'no peer has sent a row that names another';
        }

        $line = sprintf('%s a peer sent, each naming the row the peer meant', self::ids($checked));

        return $unspoken === [] ? $line : $line.sprintf('. Beside them: %s', $this->onlyThePeerKnows($unspoken));
    }

    /**
     * @param  array{checked: int, misfiled: array<string, int>, unplaceable: array<string, int>, unspoken: array<string, int>}  $census
     * @param  array<string, int>  $actionable
     */
    private function disagree(array $census, array $actionable): string
    {
        $line = sprintf(
            '%d of %s a peer sent name a different row here than the peer meant (%s)',
            array_sum($actionable),
            self::ids($census['checked']),
            $this->columns($actionable),
        );

        if ($census['unplaceable'] !== []) {
            $line .= sprintf('. %s wait on the create being taken again — run php artisan sync:repair-stranded-creates, then sync:repair-untranslated-parents', self::ids(array_sum($census['unplaceable'])));
        }

        return $census['unspoken'] === [] ? $line : $line.sprintf('. Beside them: %s', $this->onlyThePeerKnows($census['unspoken']));
    }

    /**
     * @param  array<string, int>  $unspoken
     */
    private function onlyThePeerKnows(array $unspoken): string
    {
        return sprintf(
            '%s the log holds no create for (%s) — what those mean is written in the peer\'s own aliases and nowhere here',
            self::ids(array_sum($unspoken)),
            $this->columns($unspoken),
        );
    }

    /**
     * @return array{checked: int, misfiled: array<string, int>, unplaceable: array<string, int>, unspoken: array<string, int>}
     */
    private function census(): array
    {
        $checked = 0;
        $tallies = ['misfiled' => [], 'unplaceable' => [], 'unspoken' => []];

        foreach ($this->users() as $userId) {
            $census = $this->parents->census($userId);
            $checked += $census['checked'];

            foreach ($census['findings'] as $finding) {
                $where = $finding->at->columnPath();
                $cause = $finding->verdict->value;
                $tallies[$cause][$where] = ($tallies[$cause][$where] ?? 0) + 1;
            }
        }

        return [
            'checked' => $checked,
            'misfiled' => self::ordered($tallies[UntranslatedParentVerdict::Misfiled->value]),
            'unplaceable' => self::ordered($tallies[UntranslatedParentVerdict::Unplaceable->value]),
            'unspoken' => self::ordered($tallies[UntranslatedParentVerdict::Unspoken->value]),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function columns(array $counts): string
    {
        $named = array_slice($counts, 0, self::NAME_AT_MOST, true);
        $columns = [];

        foreach ($named as $column => $count) {
            $columns[] = sprintf('%s %d', $column, $count);
        }

        return implode(', ', $columns).(count($counts) > count($named) ? ' and more' : '');
    }

    /**
     * @param  array<string, int>  $tally
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private static function added(array $tally, array $counts): array
    {
        foreach ($counts as $column => $count) {
            $tally[$column] = ($tally[$column] ?? 0) + $count;
        }

        return self::ordered($tally);
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

    // A count of what was examined rather than a bare "ok": a pass that reports
    // silence is unreadable, because a check that stopped looking reports the
    // same silence as one that looked at everything and found nothing.
    private static function ids(int $count): string
    {
        return sprintf('%d id%s', $count, $count === 1 ? '' : 's');
    }

    // Every reader on the install, not the one at the keyboard: this runs from
    // a console with no session, and a household's second member is exactly as
    // able to receive a misfiled row as the first.
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
