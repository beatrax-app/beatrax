<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Support;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScenarioSeriesResolver
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function existingScenarioIdByName(User $user, string $name): ?int
    {
        $value = $this->db->connection()->table('forecast_scenarios')
            ->where('user_id', $user->id)
            ->where('name', $name)
            ->value('id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function resolveSeriesName(stdClass $row): string
    {
        if (isset($row->display_name_override) && is_string($row->display_name_override) && $row->display_name_override !== '') {
            return $row->display_name_override;
        }
        if (isset($row->detected_name) && is_string($row->detected_name) && $row->detected_name !== '') {
            return $row->detected_name;
        }

        return Lang::get('forecasting::scenario.series_name_fallback');
    }

    // Only the three series-targeting payload kinds carry an id; a one-off or
    // a new recurring line targets no existing series.
    public function targetSeriesIdFor(ScenarioMutationPayload $payload): ?int
    {
        return match (true) {
            $payload instanceof CancelSeriesPayload => $payload->seriesId,
            $payload instanceof ChangeSeriesAmountPayload => $payload->seriesId,
            $payload instanceof ShiftSeriesDatePayload => $payload->seriesId,
            default => null,
        };
    }

    // 404, not 403: another user's series must not be distinguishable from
    // one that does not exist.
    public function assertSeriesOwnedByUser(int $seriesId, User $user): void
    {
        $owns = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->exists();
        if (! $owns) {
            throw new NotFoundHttpException('Recurring series not found.');
        }
    }
}
