<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Budgets\Models\EnvelopeSetting;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Ledger\Public\Dto\Period;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;

/**
 * @link ../../../../.docs/features/budgets/architecture.md
 */
final class EnvelopeWriter
{
    use CoercesScalars;

    private const CURRENCY = 'EUR';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
        private readonly BudgetProgressQuery $query,
    ) {}

    /**
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
            // Raw query-builder reads/writes here, NOT the EnvelopeAssignment
            // Eloquent model -- its 'date' cast would embed a spurious
            // "00:00:00" suffix on save(), breaking the fold's exact
            // where('period_start', 'Y-m-d') string match (architecture.md).
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

    // Server-side bounds check 1..200 -- a tampered Livewire payload cannot
    // persist an out-of-range value even though the column is
    // unsignedTinyInteger (which alone would reject only negative/>255).
    /**
     * @throws InvalidArgumentException category not owned/global (IDOR), or an
     *                                  out-of-range threshold
     */
    public function setNotifyThreshold(User $user, int $categoryId, ?int $thresholdPercent): void
    {
        $this->assertCategoryAccessible($user, $categoryId);

        if ($thresholdPercent !== null && ($thresholdPercent < 1 || $thresholdPercent > 200)) {
            throw new InvalidArgumentException('Notify threshold must be between 1 and 200.');
        }

        /** @var EnvelopeSettingMutated|null $event */
        $event = null;

        $this->db->connection()->transaction(function () use ($user, $categoryId, $thresholdPercent, &$event): void {
            /** @var EnvelopeSetting|null $existing */
            $existing = EnvelopeSetting::query()
                ->withoutGlobalScope(UserScope::class)
                ->where('user_id', $user->id)
                ->where('category_id', $categoryId)
                ->first();

            if ($existing instanceof EnvelopeSetting) {
                if ($existing->threshold_percent === $thresholdPercent) {
                    return;
                }

                $existing->threshold_percent = $thresholdPercent;
                $existing->save();

                $event = new EnvelopeSettingMutated(
                    settingId: $existing->id,
                    userId: $user->id,
                    mutationType: 'edit',
                    dirtyFields: ['threshold_percent' => $thresholdPercent],
                );

                return;
            }

            /** @var EnvelopeSetting $created */
            $created = EnvelopeSetting::query()->withoutGlobalScope(UserScope::class)->create([
                'user_id' => $user->id,
                'category_id' => $categoryId,
                'overspend_mode' => 'reduce_to_budget',
                'threshold_percent' => $thresholdPercent,
            ]);

            $event = new EnvelopeSettingMutated(
                settingId: $created->id,
                userId: $user->id,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $user->id,
                    'category_id' => $categoryId,
                    'overspend_mode' => 'reduce_to_budget',
                    'threshold_percent' => $thresholdPercent,
                ],
            );
        });

        if ($event !== null) {
            $this->events->dispatch($event);
        }
    }

    // Never overwrites an assignment that already exists in $toPeriod, so
    // calling this on a partially-assigned target is safe even though the
    // sole caller only offers it on an empty target.
    public function copyFromPeriod(User $user, Period $fromPeriod, Period $toPeriod): void
    {
        // Batch-load the target period's already-assigned categories once so
        // the copy never clobbers an existing target assignment.
        $existingTargetCategoryIds = $this->db->connection()
            ->table('envelope_assignments')
            ->where('user_id', $user->id)
            ->where('period_start', $toPeriod->start->toDateString())
            ->pluck('category_id')
            ->mapWithKeys(static fn (mixed $id): array => [self::toInt($id) => true])
            ->all();

        $rows = $this->db->connection()
            ->table('envelope_assignments')
            ->where('user_id', $user->id)
            ->where('period_start', $fromPeriod->start->toDateString())
            ->get(['category_id', 'assigned_minor']);

        foreach ($rows as $row) {
            $categoryId = self::toInt($row->category_id);
            $minor = self::toInt($row->assigned_minor);

            if (isset($existingTargetCategoryIds[$categoryId])) {
                continue;
            }

            if ($minor > 0) {
                $this->setAssigned($user, $categoryId, $toPeriod->start, $minor);
            }
        }
    }

    /**
     * @return int the stable `envelope_moves.id` of the source (debit) row —
     *             pass this to `undoMove()` to reverse the whole pair
     *
     * @throws InvalidArgumentException same source/target, non-positive
     *                                  amount, or a category not owned/global (IDOR)
     */
    public function move(User $user, int $fromCategoryId, int $toCategoryId, CarbonImmutable $periodStart, int $minor, ?string $memo = null): int
    {
        if ($fromCategoryId === $toCategoryId) {
            throw new InvalidArgumentException('Source and destination envelope must be different.');
        }

        if ($minor <= 0) {
            throw new InvalidArgumentException('Invalid or non-positive amount.');
        }

        $this->assertCategoryAccessible($user, $fromCategoryId);
        $this->assertCategoryAccessible($user, $toCategoryId);

        $periodDate = $periodStart->toDateString();

        /** @var array{debitId: int, creditId: int}|null $ids */
        $ids = null;

        // One shared correlation id for both paired rows, so undoMove()
        // matches the counterpart deterministically rather than by
        // second-precision created_at.
        $groupId = (string) Str::uuid();

        $this->db->connection()->transaction(function () use ($user, $fromCategoryId, $toCategoryId, $periodDate, $minor, $memo, $groupId, &$ids): void {
            $connection = $this->db->connection();
            $now = $this->clock->now();

            $debitId = self::toInt($connection->table('envelope_moves')->insertGetId([
                'user_id' => $user->id,
                'category_id' => $fromCategoryId,
                'counterpart_category_id' => $toCategoryId,
                'period_start' => $periodDate,
                'amount_minor' => -$minor,
                'currency' => self::CURRENCY,
                'kind' => 'move_out',
                'memo' => $memo,
                'move_group_id' => $groupId,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            $creditId = self::toInt($connection->table('envelope_moves')->insertGetId([
                'user_id' => $user->id,
                'category_id' => $toCategoryId,
                'counterpart_category_id' => $fromCategoryId,
                'period_start' => $periodDate,
                'amount_minor' => $minor,
                'currency' => self::CURRENCY,
                'kind' => 'move_in',
                'memo' => $memo,
                'move_group_id' => $groupId,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            $ids = ['debitId' => $debitId, 'creditId' => $creditId];
        });

        if ($ids === null) {
            throw new \RuntimeException('Move failed to write both paired rows.');
        }

        $this->events->dispatch(new EnvelopeMoveMutated(
            moveId: $ids['debitId'],
            userId: $user->id,
            mutationType: 'create',
            dirtyFields: [
                'user_id' => $user->id,
                'category_id' => $fromCategoryId,
                'counterpart_category_id' => $toCategoryId,
                'period_start' => $periodDate,
                'amount_minor' => -$minor,
                'currency' => self::CURRENCY,
                'kind' => 'move_out',
                'move_group_id' => $groupId,
            ],
        ));

        $this->events->dispatch(new EnvelopeMoveMutated(
            moveId: $ids['creditId'],
            userId: $user->id,
            mutationType: 'create',
            dirtyFields: [
                'user_id' => $user->id,
                'category_id' => $toCategoryId,
                'counterpart_category_id' => $fromCategoryId,
                'period_start' => $periodDate,
                'amount_minor' => $minor,
                'currency' => self::CURRENCY,
                'kind' => 'move_in',
                'move_group_id' => $groupId,
            ],
        ));

        return $ids['debitId'];
    }

    // A foreign or missing move id is a silent no-op (mirrors
    // PotWriter::archive's cross-user handling).
    public function undoMove(User $user, int $moveId): void
    {
        $connection = $this->db->connection();

        /** @var \stdClass|null $row */
        $row = $connection->table('envelope_moves')
            ->where('id', $moveId)
            ->where('user_id', $user->id)
            ->first(['id', 'category_id', 'counterpart_category_id', 'period_start', 'amount_minor', 'created_at', 'move_group_id']);

        if ($row === null) {
            return;
        }

        $categoryId = self::toInt($row->category_id);
        $counterpartId = self::toInt($row->counterpart_category_id);
        $periodStartRaw = $row->period_start;
        $amountMinor = self::toInt($row->amount_minor);
        $createdAtRaw = $row->created_at;
        $groupId = is_string($row->move_group_id) && $row->move_group_id !== '' ? $row->move_group_id : null;

        // Matches the paired counterpart by its shared move_group_id when
        // present -- deterministic even when two moves share a wall-clock
        // second. Legacy rows written before move_group_id existed fall
        // back to the original created_at-based match.
        $pairQuery = $connection->table('envelope_moves')
            ->where('user_id', $user->id)
            ->where('id', '!=', $moveId);

        if ($groupId !== null) {
            $pairQuery->where('move_group_id', $groupId);
        } else {
            $pairQuery->where('category_id', $counterpartId)
                ->where('counterpart_category_id', $categoryId)
                ->where('period_start', $periodStartRaw)
                ->where('amount_minor', -$amountMinor)
                ->where('created_at', $createdAtRaw);
        }

        /** @var \stdClass|null $pairRow */
        $pairRow = $pairQuery->first(['id']);

        $pairId = $pairRow !== null ? self::toInt($pairRow->id) : null;

        $connection->transaction(function () use ($connection, $moveId, $pairId): void {
            $connection->table('envelope_moves')->where('id', $moveId)->delete();
            if ($pairId !== null) {
                $connection->table('envelope_moves')->where('id', $pairId)->delete();
            }
        });

        $this->events->dispatch(new EnvelopeMoveMutated(moveId: $moveId, userId: $user->id, mutationType: 'delete'));
        if ($pairId !== null) {
            $this->events->dispatch(new EnvelopeMoveMutated(moveId: $pairId, userId: $user->id, mutationType: 'delete'));
        }
    }

    // Handles the Dutch grouped form "1.234,56" and the plain forms
    // "1234.56" / "50,00" / "50": the rightmost of '.'/',' is the decimal
    // separator and the other is thousands. Copied verbatim from
    // BudgetWriter (proven, unit-tested) -- do not re-implement.
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
     * @throws InvalidArgumentException
     */
    private function assertCategoryAccessible(User $user, int $categoryId): void
    {
        if (! $this->query->canBudget($user, $categoryId)) {
            throw new InvalidArgumentException('Category not found or not accessible by user.');
        }
    }
}
