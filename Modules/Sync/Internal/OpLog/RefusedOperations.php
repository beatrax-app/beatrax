<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;

// What this device refused, counted the way a reader would count it and split
// by whether anything can still undo the refusal. The status line above the
// device list is the only place a reader looks to answer "is my data here",
// and it had no way to ask this question at all.
/**
 * @link ../../../../.docs/features/sync/what-the-quarantine-tells-the-reader.md#the-refusals-the-top-line-could-not-see
 */
final readonly class RefusedOperations
{
    public function __construct(private DatabaseManager $db) {}

    // The two halves of the quarantine, each an exact count of its own. Never
    // one number for both: a refusal nothing takes again and one a pass can
    // still answer are different facts, and their sum is neither of them.
    /**
     * @return array{terminal: int, recoverable: int}
     */
    public function tally(int $userId): array
    {
        return [
            'terminal' => $this->under($userId, QuarantineOutcome::terminalReasonValues())['tally'],
            'recoverable' => $this->under($userId, QuarantineReason::recoverable())['tally'],
        ];
    }

    // Records, never entries: a create is captured per FIELD, so the eight
    // entries one refused transaction produces are one record missing from this
    // device, and counting entries overstates the damage by the width of the
    // table. The separator cannot collide — a table name is an identifier.
    /**
     * @param  list<string>  $reasons
     * @return array{tally: int, newest: ?string}
     */
    private function under(int $userId, array $reasons): array
    {
        if ($reasons === []) {
            return ['tally' => 0, 'newest' => null];
        }

        $row = $this->db->connection()
            ->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->whereIn('reason', $reasons)
            ->selectRaw("COUNT(DISTINCT table_name || ':' || pk) AS tally, MAX(created_at) AS newest")
            ->first();

        $fields = $row === null ? [] : get_object_vars($row);

        return [
            'tally' => is_numeric($fields['tally'] ?? null) ? (int) $fields['tally'] : 0,
            'newest' => is_string($fields['newest'] ?? null) && $fields['newest'] !== '' ? $fields['newest'] : null,
        ];
    }
}
