<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

/**
 * The ONE place any notification key is computed (D-38 invariant 3,
 * enforced by plan 18-17's BoundaryArchTest). Derives the deterministic
 * sha256 string primary key for the `notifications` table (D-05/D-06)
 * from the tuple `(user_id, trigger_type, subject_key, occurrence)`.
 *
 * Byte-identical input MUST produce byte-identical output across two
 * INDEPENDENT devices / process invocations — that identity IS the CRDT
 * convergence mechanism this phase relies on (Reqs 1 + 12). Two devices
 * that each independently decide "this user's forecast just went into
 * shortfall for period 2026-08" derive the SAME row id and therefore
 * converge on one notification row with no sync-engine change: the
 * dedup happens at INSERT time via the primary key, not via a
 * post-hoc merge step.
 *
 * Key ordering is fixed by the literal array order in `derive()` below —
 * callers must never build the payload from a caller-supplied array,
 * because a differently-ordered `json_encode` would silently change the
 * digest and break convergence between devices running different code
 * paths.
 *
 * The `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE` flag pair is copied
 * verbatim from `OpLogEntry::signingPayload()`'s determinism discipline —
 * unescaped unicode keeps a non-ASCII `subject_key` (e.g. a merchant
 * name) byte-identical across encodes, and THROW_ON_ERROR turns any
 * encoding failure into a loud `JsonException` rather than a silently
 * `false` return (PHPStan level 10 strict forbids ignoring that return
 * value unchecked).
 */
final class DeterministicKeyDeriver
{
    public const TRIGGER_IMPORT_FINISHED = 'import_finished';

    public const TRIGGER_RECEIPTS_FOUND = 'receipts_found';

    public const TRIGGER_DRIFT_CHANGED = 'drift_changed';

    public const TRIGGER_FORECAST_SHORTFALL = 'forecast_shortfall';

    public const TRIGGER_PAYMENT_REMINDER = 'payment_reminder';

    public const TRIGGER_POSITION_DIGEST = 'position_digest';

    public const TRIGGER_BUDGET_NUDGE = 'budget_nudge';

    public const TRIGGER_SAVINGS_PROMPT = 'savings_prompt';

    /** Phase 19 (Req 14, D-14/D-15) — the ICS "statement ready" nudge. */
    public const TRIGGER_ICS_STATEMENT_READY = 'ics_statement_ready';

    public function derive(int $userId, string $triggerType, string $subjectKey, string $occurrence): string
    {
        $payload = json_encode(
            [
                'user_id' => $userId,
                'trigger_type' => $triggerType,
                'subject_key' => $subjectKey,
                'occurrence' => $occurrence,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $payload);
    }
}
