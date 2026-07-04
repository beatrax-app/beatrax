<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Budgets\Models\EnvelopeSetting;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Ledger\Public\Dto\Period;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;

/**
 * The single write path for zero-based envelope budgeting (Req 1/4/5/6/8).
 *
 * Mirrors PotWriter/BudgetWriter's shapes exactly, with one deliberate
 * omission: `move()` carries NO balance guard (Req 8) — a move may take the
 * source envelope negative and that is the intended, correct behavior for
 * zero-based budgeting (RESEARCH.md Pitfall 1, the highest-risk trap in this
 * phase).
 *
 * Responsibilities:
 *  - `setAssigned()`: upserts one (user, category, period) row, PK-preserving
 *    on edit (D-01). Setting the amount to zero DELETES the row rather than
 *    storing a zero (D-06 absence == 0) and dispatches a 'delete' event, not
 *    an 'edit' with value 0 — the two converge differently under per-
 *    (table,pk,field) LWW sync merge.
 *  - `move()` / `undoMove()`: a paired debit/credit row written in one DB
 *    transaction (D-02), NEVER balance-guarded. Undo hard-deletes both
 *    paired rows (mirrors the tombstone-undo precedent in
 *    TransactionDetail::reclassify).
 *  - `setOverspendMode()`: upserts one (user, category) envelope_settings row.
 *  - `copyFromPeriod()`: reproduces each prior-period assigned amount into a
 *    new period for envelopes that had one; never-assigned envelopes stay
 *    unassigned (Req 6).
 *  - Every client-supplied category id is re-validated server-side via
 *    `BudgetProgressQuery::canBudget()` before any write (IDOR — T-13.2-04-01).
 *  - Every Envelope*Mutated event is dispatched AFTER its DB transaction
 *    commits, never from inside it (WR-06 contract).
 */
final class EnvelopeWriter
{
    private const CURRENCY = 'EUR';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
        private readonly BudgetProgressQuery $query,
    ) {}

    /**
     * Set the assigned amount for (user, category, period). Upserts a row
     * via a PK-preserving update-in-place when one already exists (D-01);
     * setting `$minor` to 0 deletes the row instead of storing a zero (D-06).
     *
     * @throws InvalidArgumentException category not owned/global (IDOR)
     */
    public function setAssigned(User $user, int $categoryId, CarbonImmutable $periodStart, int $minor): void
    {
        $this->assertCategoryAccessible($user, $categoryId);

        if ($minor < 0) {
            throw new InvalidArgumentException('Assigned amount cannot be negative.');
        }

        $periodDate = $periodStart->toDateString();

        /** @var EnvelopeAssignmentMutated|null $event */
        $event = null;

        $this->db->connection()->transaction(function () use ($user, $categoryId, $periodDate, $minor, &$event): void {
            // Raw query-builder reads/writes here — NOT the EnvelopeAssignment
            // Eloquent model — deliberately: the model's `period_start` cast
            // ('date') serializes on save() using the connection grammar's
            // full datetime format ("Y-m-d H:i:s"), embedding a spurious
            // "00:00:00" suffix (the exact Plan 03 CarryoverQuery pitfall).
            // A plain-date string written here keeps period_start queryable
            // by exact string equality and matches what assertDatabaseHas
            // expects (Rule 1 fix — see 13.2-04-SUMMARY.md).
            $connection = $this->db->connection();

            /** @var \stdClass|null $existing */
            $existing = $connection->table('envelope_assignments')
                ->where('user_id', $user->id)
                ->where('category_id', $categoryId)
                ->where('period_start', $periodDate)
                ->first(['id', 'assigned_minor']);

            if ($minor === 0) {
                if ($existing !== null) {
                    $id = self::toInt($existing->id);
                    $connection->table('envelope_assignments')->where('id', $id)->delete();
                    $event = new EnvelopeAssignmentMutated(
                        assignmentId: $id,
                        userId: $user->id,
                        mutationType: 'delete',
                    );
                }

                return;
            }

            if ($existing !== null) {
                $id = self::toInt($existing->id);
                if (self::toInt($existing->assigned_minor) === $minor) {
                    return;
                }

                $connection->table('envelope_assignments')->where('id', $id)->update([
                    'assigned_minor' => $minor,
                    'currency' => self::CURRENCY,
                    'updated_at' => $this->clock->now(),
                ]);

                $event = new EnvelopeAssignmentMutated(
                    assignmentId: $id,
                    userId: $user->id,
                    mutationType: 'edit',
                    dirtyFields: ['assigned_minor' => $minor],
                );

                return;
            }

            $now = $this->clock->now();
            $newId = self::toInt($connection->table('envelope_assignments')->insertGetId([
                'user_id' => $user->id,
                'category_id' => $categoryId,
                'period_start' => $periodDate,
                'assigned_minor' => $minor,
                'currency' => self::CURRENCY,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            $event = new EnvelopeAssignmentMutated(
                assignmentId: $newId,
                userId: $user->id,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $user->id,
                    'category_id' => $categoryId,
                    'period_start' => $periodDate,
                    'assigned_minor' => $minor,
                    'currency' => self::CURRENCY,
                ],
            );
        });

        if ($event !== null) {
            $this->events->dispatch($event);
        }
    }

    /**
     * Toggle the overspend-carry behavior for (user, category) — upserts one
     * envelope_settings row (D-03).
     *
     * @throws InvalidArgumentException category not owned/global (IDOR), or
     *                                  an unrecognised mode
     */
    public function setOverspendMode(User $user, int $categoryId, string $mode): void
    {
        $this->assertCategoryAccessible($user, $categoryId);

        if (! in_array($mode, ['reduce_to_budget', 'carry_negative'], true)) {
            throw new InvalidArgumentException('Invalid overspend mode.');
        }

        /** @var EnvelopeSettingMutated|null $event */
        $event = null;

        $this->db->connection()->transaction(function () use ($user, $categoryId, $mode, &$event): void {
            /** @var EnvelopeSetting|null $existing */
            $existing = EnvelopeSetting::query()
                ->withoutGlobalScope(UserScope::class)
                ->where('user_id', $user->id)
                ->where('category_id', $categoryId)
                ->first();

            if ($existing instanceof EnvelopeSetting) {
                if ($existing->overspend_mode === $mode) {
                    return;
                }

                $existing->overspend_mode = $mode;
                $existing->save();

                $event = new EnvelopeSettingMutated(
                    settingId: $existing->id,
                    userId: $user->id,
                    mutationType: 'edit',
                    dirtyFields: ['overspend_mode' => $mode],
                );

                return;
            }

            /** @var EnvelopeSetting $created */
            $created = EnvelopeSetting::query()->withoutGlobalScope(UserScope::class)->create([
                'user_id' => $user->id,
                'category_id' => $categoryId,
                'overspend_mode' => $mode,
            ]);

            $event = new EnvelopeSettingMutated(
                settingId: $created->id,
                userId: $user->id,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $user->id,
                    'category_id' => $categoryId,
                    'overspend_mode' => $mode,
                ],
            );
        });

        if ($event !== null) {
            $this->events->dispatch($event);
        }
    }

    /**
     * Reproduce every assigned amount from `$fromPeriod` into `$toPeriod`
     * (Req 6) — an envelope that was never assigned in `$fromPeriod` stays
     * unassigned in `$toPeriod`. Delegates to `setAssigned()` per envelope so
     * IDOR-guarding, PK-preservation, and event dispatch stay in one place.
     */
    public function copyFromPeriod(User $user, Period $fromPeriod, Period $toPeriod): void
    {
        $rows = $this->db->connection()
            ->table('envelope_assignments')
            ->where('user_id', $user->id)
            ->where('period_start', $fromPeriod->start->toDateString())
            ->get(['category_id', 'assigned_minor']);

        foreach ($rows as $row) {
            $categoryId = self::toInt($row->category_id);
            $minor = self::toInt($row->assigned_minor);

            if ($minor > 0) {
                $this->setAssigned($user, $categoryId, $toPeriod->start, $minor);
            }
        }
    }

    /**
     * Parse a user-entered amount into positive integer minor units, or null
     * if invalid. Handles the Dutch grouped form "1.234,56" and the plain
     * forms "1234.56" / "50,00" / "50": the rightmost of '.'/',' is the
     * decimal separator and the other is thousands. The whole part is capped
     * at 12 digits so the cents arithmetic cannot overflow PHP_INT_MAX.
     *
     * COPIED VERBATIM from BudgetWriter (proven, unit-tested). Do not
     * re-implement.
     */
    public function parseAmount(string $value): ?int
    {
        $normalised = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($normalised === '') {
            return null;
        }

        $lastDot = strrpos($normalised, '.');
        $lastComma = strrpos($normalised, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $normalised = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $normalised)
                : str_replace(',', '', $normalised);
        } elseif ($lastComma !== false) {
            $normalised = str_replace(',', '.', $normalised);
        }

        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $normalised) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $normalised, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($frac, 2, '0');

        return $minor > 0 ? $minor : null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Re-validate that `$categoryId` is one the user may budget — one of
     * their own or a global EXPENSE category (T-13.2-04-01 IDOR guard).
     * Never trust a client-supplied category id just because it appeared in
     * a rendered `<select>`.
     *
     * @throws InvalidArgumentException
     */
    private function assertCategoryAccessible(User $user, int $categoryId): void
    {
        if (! $this->query->canBudget($user, $categoryId)) {
            throw new InvalidArgumentException('Category not found or not accessible by user.');
        }
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
