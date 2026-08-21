<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Budgets\Models\EnvelopeSetting;
use Modules\Budgets\Public\Enums\OverspendMode;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;

final class EnvelopeWriter
{
    use CoercesScalars;

    private const CURRENCY = 'EUR';

    public const MIN_NOTIFY_THRESHOLD_PERCENT = 1;

    public const MAX_NOTIFY_THRESHOLD_PERCENT = 200;

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
            throw new InvalidArgumentException(Lang::get('budgets::messages.errors.assigned_negative'));
        }

        $periodDate = $periodStart->toDateString();

        /** @var EnvelopeAssignmentMutated|null $event */
        $event = null;

        $this->db->connection()->transaction(function () use ($user, $categoryId, $periodDate, $minor, &$event): void {
            // Query builder, not the EnvelopeAssignment model: its 'date' cast
            // writes a "00:00:00" suffix that misses the fold's exact
            // where('period_start', 'Y-m-d') string match.
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

        if (! in_array($mode, [OverspendMode::ReduceToBudget->value, OverspendMode::CarryNegative->value], true)) {
            throw new InvalidArgumentException(Lang::get('budgets::messages.errors.invalid_overspend_mode'));
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

    // The column is unsignedTinyInteger, which alone would accept anything up to
    // 255, so a tampered Livewire payload is bounded here instead.
    /**
     * @throws InvalidArgumentException category not owned/global (IDOR), or an
     *                                  out-of-range threshold
     */
    public function setNotifyThreshold(User $user, int $categoryId, ?int $thresholdPercent): void
    {
        $this->assertCategoryAccessible($user, $categoryId);

        if ($thresholdPercent !== null
            && ($thresholdPercent < self::MIN_NOTIFY_THRESHOLD_PERCENT || $thresholdPercent > self::MAX_NOTIFY_THRESHOLD_PERCENT)) {
            throw new InvalidArgumentException(Lang::get('budgets::messages.errors.threshold_range'));
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
                'overspend_mode' => OverspendMode::ReduceToBudget->value,
                'threshold_percent' => $thresholdPercent,
            ]);

            $event = new EnvelopeSettingMutated(
                settingId: $created->id,
                userId: $user->id,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $user->id,
                    'category_id' => $categoryId,
                    'overspend_mode' => OverspendMode::ReduceToBudget->value,
                    'threshold_percent' => $thresholdPercent,
                ],
            );
        });

        if ($event !== null) {
            $this->events->dispatch($event);
        }
    }

    // Never overwrites an assignment already present in $toPeriod, so a
    // partially-assigned target is safe even though the caller only offers
    // this on an empty one.
    public function copyFromPeriod(User $user, Period $fromPeriod, Period $toPeriod): void
    {
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
            throw new InvalidArgumentException(Lang::get('budgets::messages.errors.same_envelope'));
        }

        if ($minor <= 0) {
            throw new InvalidArgumentException(Lang::get('budgets::messages.errors.non_positive_amount'));
        }

        $this->assertCategoryAccessible($user, $fromCategoryId);
        $this->assertCategoryAccessible($user, $toCategoryId);

        $periodDate = $periodStart->toDateString();

        // Shared by both rows so undoMove() finds the counterpart deterministically,
        // not by a created_at that is only second-precision.
        $groupId = (string) Str::uuid();

        /** @var array{debitId: int, creditId: int} $ids */
        $ids = $this->db->connection()->transaction(function () use ($user, $fromCategoryId, $toCategoryId, $periodDate, $minor, $memo, $groupId): array {
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

            return ['debitId' => $debitId, 'creditId' => $creditId];
        });

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

    // A move id belonging to another user is treated exactly like a missing one:
    // this returns silently rather than confirming that the id exists.
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

        // move_group_id pairs the two rows even when two moves land in the same
        // wall-clock second. Rows written before the column existed still have to
        // fall back to matching on created_at.
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

    public function parseAmount(string $value): ?int
    {
        return MoneyInput::tryToPositiveMinor($value);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertCategoryAccessible(User $user, int $categoryId): void
    {
        if (! $this->query->canBudget($user, $categoryId)) {
            throw new InvalidArgumentException(Lang::get('budgets::messages.errors.category_not_found'));
        }
    }
}
