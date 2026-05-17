<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Models\RecurringSeries;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Writes `recurring_series.display_name_override` directly. This is a
 * metric-style write — no state transition, no audit row, no event —
 * because the `state` column is unchanged and the column is the
 * documented Public-readable override that survives subsequent
 * detector sweeps (the detector refreshes `detected_name` but never
 * clobbers `display_name_override`).
 *
 * Passing `null` clears the override so the read site falls back to
 * the auto-derived detected_name.
 *
 * Cross-user invocation raises NotFoundHttpException (404).
 */
final class EditRecurringSeriesName
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $seriesId, User $user, ?string $displayNameOverride): void
    {
        /** @var RecurringSeries|null $series */
        $series = RecurringSeries::query()
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first();

        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }

        $now = $this->clock->now()->toDateTimeString();

        $this->db->connection()->table('recurring_series')
            ->where('id', $series->id)
            ->update([
                'display_name_override' => $displayNameOverride,
                'updated_at' => $now,
            ]);
    }
}
