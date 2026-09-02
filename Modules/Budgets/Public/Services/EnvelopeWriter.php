<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Budgets\Models\EnvelopeSetting;
use Modules\Budgets\Public\Enums\EnvelopeMoveKind;
use Modules\Budgets\Public\Enums\OverspendMode;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Exceptions\IdReadBackFailedException;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\IdReadBack;
use Modules\Core\Public\Support\Lang;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;
use Modules\Sync\Public\Events\EnvelopeMoveMutated;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;

final readonly class EnvelopeWriter
{
    use CoercesScalars;

    public const int MIN_NOTIFY_THRESHOLD_PERCENT = 1;

    public const int MAX_NOTIFY_THRESHOLD_PERCENT = 200;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
        private BudgetProgressQuery $query,
        private BaseCurrency $baseCurrency,
        private PeriodQuery $periods,
        private CrossCurrencyTotal $fx,
    ) {}

    /**
     * @throws InvalidArgumentException category not owned/global (IDOR)
     * @throws IdReadBackFailedException the inserted row could not be read back, so the write rolled back
     */
    public function setAssigned(User $user, int $categoryId, CarbonImmutable $periodStart, int $minor): void
    {
        $this->assertCategoryAccessible($user, $categoryId);

        if ($minor < 0) {
            throw new InvalidArgumentException(Lang::get('budgets::messages.errors.assigned_negative'));
        }

        // CarryoverQuery matches period_start exactly, so a date that is not the
        // owner's own period start keys a row no period the fold walks can find.
        // Normalised here rather than at each caller: an import writing a source
        // file's month boundary must land where the reader's calendar puts it.
        $periodDate = $this->periods->containingForUser($user, $periodStart)->start->toDateString();

        /** @var EnvelopeAssignmentMutated|null $event */
        $event = null;

        $this->db->connection()->transaction(function () use ($user, $categoryId, $periodDate, $minor, &$event): void {
            $event = $this->applyAssignment($user, $categoryId, $periodDate, $minor);
        });

        if ($event !== null) {
            $this->events->dispatch($event);
        }
    }

    // The one assignment write, shared by setAssigned() and copyFromPeriod() so
    // the latter can put a whole month inside one transaction. The caller owns
    // the transaction and the after-commit dispatch.
    /**
     * @link ../../../../.docs/features/core/an-id-read-after-an-insert.md
     */
    private function applyAssignment(User $user, int $categoryId, string $periodDate, int $minor, ?string $currency = null): ?EnvelopeAssignmentMutated
    {
        // Query builder, not the EnvelopeAssignment model: its 'date' cast
        // writes a "00:00:00" suffix that misses the fold's exact
        // where('period_start', 'Y-m-d') string match.
        $connection = $this->db->connection();
        $currency ??= $this->baseCurrency->forUser($user);

        /** @var \stdClass|null $existing */
        $existing = $connection->table('envelope_assignments')
            ->where('user_id', $user->id)
            ->where('category_id', $categoryId)
            ->where('period_start', $periodDate)
            ->first(['id', 'assigned_minor', 'currency']);

        // The currency is half the amount. A reader who switched their reporting
        // currency and re-typed the figure the grid showed them sent the same
        // minor under a different sign, and dropping that as "unchanged" left
        // the row denominated in the old one.
        $unchanged = $existing !== null
            && self::toInt($existing->assigned_minor) === $minor
            && self::toString($existing->currency) === $currency;

        // Clearing a row that is not there writes nothing, and neither does
        // re-sending the figure already stored.
        if ($unchanged || ($minor === 0 && $existing === null)) {
            return null;
        }

        if ($existing !== null) {
            $id = self::toInt($existing->id);

            if ($minor === 0) {
                $connection->table('envelope_assignments')->where('id', $id)->delete();

                $mutation = new EnvelopeAssignmentMutated(
                    assignmentId: $id,
                    userId: $user->id,
                    mutationType: 'delete',
                );
            } else {
                $connection->table('envelope_assignments')->where('id', $id)->update([
                    'assigned_minor' => $minor,
                    'currency' => $currency,
                    'updated_at' => $this->clock->now(),
                ]);

                $mutation = new EnvelopeAssignmentMutated(
                    assignmentId: $id,
                    userId: $user->id,
                    mutationType: 'edit',
                    dirtyFields: ['assigned_minor' => $minor, 'currency' => $currency],
                );
            }

            return $mutation;
        }

        $now = $this->clock->now();
        $connection->table('envelope_assignments')->insert([
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'period_start' => $periodDate,
            'assigned_minor' => $minor,
            'currency' => $currency,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The id is read back by the same three columns the lookup above uses,
        // never taken from insertGetId(): lastInsertId() is per connection, and
        // the sidebar's badge listener writes a `cache` row from inside this
        // INSERT's own event. A wrong id here is a sync op against a stranger.
        $newId = IdReadBack::of($connection, 'envelope_assignments', [
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'period_start' => $periodDate,
        ]);

        return new EnvelopeAssignmentMutated(
            assignmentId: $newId,
            userId: $user->id,
            mutationType: 'create',
            dirtyFields: [
                'user_id' => $user->id,
                'category_id' => $categoryId,
                'period_start' => $periodDate,
                'assigned_minor' => $minor,
                'currency' => $currency,
            ],
        );
    }

    /**
     * @throws InvalidArgumentException category not owned/global (IDOR)
     */
    public function setOverspendMode(User $user, int $categoryId, OverspendMode $mode): void
    {
        $this->assertCategoryAccessible($user, $categoryId);

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
                if ($existing->overspend_mode === $mode->value) {
                    return;
                }

                $existing->overspend_mode = $mode->value;
                $existing->save();

                $event = new EnvelopeSettingMutated(
                    settingId: $existing->id,
                    userId: $user->id,
                    mutationType: 'edit',
                    dirtyFields: ['overspend_mode' => $mode->value],
                );

                return;
            }

            /** @var EnvelopeSetting $created */
            $created = EnvelopeSetting::query()->withoutGlobalScope(UserScope::class)->create([
                'user_id' => $user->id,
                'category_id' => $categoryId,
                'overspend_mode' => $mode->value,
            ]);

            $event = new EnvelopeSettingMutated(
                settingId: $created->id,
                userId: $user->id,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $user->id,
                    'category_id' => $categoryId,
                    'overspend_mode' => $mode->value,
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
            ->get(['category_id', 'assigned_minor', 'currency']);

        $periodDate = $this->periods->containingForUser($user, $toPeriod->start)->start->toDateString();
        $baseCurrency = $this->baseCurrency->forUser($user);
        $rates = $this->fx->ratesTo(
            array_values(array_map(static fn (\stdClass $row): string => self::toString($row->currency), $rows->all())),
            $baseCurrency,
        );

        /** @var list<EnvelopeAssignmentMutated> $dispatchAfterCommit */
        $dispatchAfterCommit = [];

        // One transaction for the whole month: a copy that stopped half-way left
        // a partially-assigned period indistinguishable from a deliberate one.
        $this->db->connection()->transaction(function () use ($user, $rows, $existingTargetCategoryIds, $periodDate, $baseCurrency, $rates, &$dispatchAfterCommit): void {
            foreach ($rows as $row) {
                $event = $this->copyAssignment($user, $row, $existingTargetCategoryIds, $periodDate, $baseCurrency, $rates);

                if ($event !== null) {
                    $dispatchAfterCommit[] = $event;
                }
            }
        });

        foreach ($dispatchAfterCommit as $event) {
            $this->events->dispatch($event);
        }
    }

    // The source row records the currency it was written in, which is not
    // necessarily the one the reader budgets in today: handing the raw minor
    // on invented the difference between the two on every envelope of the
    // month, from one click.
    /**
     * @param  array<array-key, mixed>  $existingTargetCategoryIds  keyed by the category ids already assigned in the target period
     * @param  array<string, string>  $rates
     *
     * @throws InvalidArgumentException category not owned/global (IDOR)
     */
    private function copyAssignment(
        User $user,
        \stdClass $row,
        array $existingTargetCategoryIds,
        string $periodDate,
        string $baseCurrency,
        array $rates,
    ): ?EnvelopeAssignmentMutated {
        $categoryId = self::toInt($row->category_id);
        $minor = self::toInt($row->assigned_minor);

        if ($minor <= 0 || isset($existingTargetCategoryIds[$categoryId])) {
            return null;
        }

        $this->assertCategoryAccessible($user, $categoryId);

        $source = Money::tryOfMinor($minor, self::toString($row->currency));
        $converted = $source === null ? null : $this->fx->convert($source, $baseCurrency, $rates);

        return $converted === null
            ? $this->applyAssignment($user, $categoryId, $periodDate, $minor, self::toString($row->currency))
            : $this->applyAssignment($user, $categoryId, $periodDate, $converted->toMinor(), $baseCurrency);
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

        // Same key the fold matches assignments on, so a move made from a date
        // inside the period lands in the period rather than beside it.
        $periodDate = $this->periods->containingForUser($user, $periodStart)->start->toDateString();
        $currency = $this->baseCurrency->forUser($user);

        // Shared by both rows so undoMove() finds the counterpart deterministically,
        // not by a created_at that is only second-precision.
        $groupId = (string) Str::uuid();

        /** @var array{debitId: int, creditId: int} $ids */
        $ids = $this->db->connection()->transaction(function () use ($user, $fromCategoryId, $toCategoryId, $periodDate, $currency, $minor, $memo, $groupId): array {
            $connection = $this->db->connection();
            $now = $this->clock->now();

            $debitId = self::toInt($connection->table('envelope_moves')->insertGetId([
                'user_id' => $user->id,
                'category_id' => $fromCategoryId,
                'counterpart_category_id' => $toCategoryId,
                'period_start' => $periodDate,
                'amount_minor' => -$minor,
                'currency' => $currency,
                'kind' => EnvelopeMoveKind::MoveOut->value,
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
                'currency' => $currency,
                'kind' => EnvelopeMoveKind::MoveIn->value,
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
                'currency' => $currency,
                'kind' => EnvelopeMoveKind::MoveOut->value,
                'memo' => $memo,
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
                'currency' => $currency,
                'kind' => EnvelopeMoveKind::MoveIn->value,
                'memo' => $memo,
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

    // The denomination the envelope is kept in, which is the reader's own
    // reporting currency: a yen has no hundredth, so a plan typed as 50000 was
    // banked as ¥5,000,000 against a grid still reading ¥50,000.
    public function parseAmount(string $value, ?string $currencyCode = null): ?int
    {
        return MoneyInput::tryToPositiveMinor($value, $currencyCode);
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
