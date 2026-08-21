<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Models\RecurringSeries;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Clearing the override is what an empty value means; the read site then falls
// back to detected_name, which this never writes. Over-long input raises rather
// than reaching the column as a "Data too long" 500.

final class EditRecurringSeriesName
{
    // Well under the column's VARCHAR(255), so this cap always binds first.
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
