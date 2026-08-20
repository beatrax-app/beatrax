<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Models\RecurringSeries;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// A null or empty-after-trim value clears the override (read site falls
// back to the detector-refreshed detected_name, which this write never
// touches). Strings over MAX_LENGTH raise InvalidArgumentException rather
// than a schema-level "Data too long for column" 500.

final class EditRecurringSeriesName
{
    // Below the underlying VARCHAR(255) so a future column-type change
    // cannot silently break the cap.
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
