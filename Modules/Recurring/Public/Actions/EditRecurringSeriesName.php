<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
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
 * Input handling:
 *  - `null` clears the override; the read site falls back to the
 *    auto-derived detected_name.
 *  - Strings are trimmed; an empty-after-trim value is treated as a
 *    clear (same shape as `null`).
 *  - Strings longer than `MAX_LENGTH` raise `InvalidArgumentException`
 *    rather than letting the schema raise a "Data too long for
 *    column" 500. The cap is below the underlying VARCHAR(255) limit
 *    so a future-proof grace margin survives column-type changes.
 *
 * Cross-user invocation raises NotFoundHttpException (404).
 */
final class EditRecurringSeriesName
{
    /**
     * Maximum permitted display-name length in unicode characters.
     * Lower than the underlying VARCHAR(255) so a future column-type
     * tweak does not silently break the cap.
     */
    private const MAX_LENGTH = 120;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $seriesId, User $user, ?string $displayNameOverride): void
    {
        if ($displayNameOverride !== null) {
            $displayNameOverride = trim($displayNameOverride);
            if ($displayNameOverride === '') {
                $displayNameOverride = null;
            } elseif (mb_strlen($displayNameOverride) > self::MAX_LENGTH) {
                throw new InvalidArgumentException(
                    'Display name must be '.self::MAX_LENGTH.' characters or fewer.',
                );
            }
        }

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
