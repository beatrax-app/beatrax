<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreateCancellationScenarioForSeries
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CreateScenario $createScenario,
        private readonly AddScenarioMutation $addMutation,
    ) {}

    public function __invoke(int $recurringSeriesId, User $user): int
    {
        $series = $this->db->connection()->table('recurring_series')
            ->where('id', $recurringSeriesId)
            ->where('user_id', $user->id)
            ->first(['id', 'detected_name', 'display_name_override']);
        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }
        /** @var stdClass $series */
        $name = $this->resolveSeriesName($series);

        $scenarioName = "Cancel {$name}";

        try {
            return $this->db->connection()->transaction(function () use ($user, $scenarioName, $recurringSeriesId): int {
                $scenarioId = ($this->createScenario)($user, $scenarioName);
                ($this->addMutation)($scenarioId, $user, ScenarioMutationKind::CancelSeries->value, new CancelSeriesPayload(seriesId: $recurringSeriesId));

                return $scenarioId;
            });
        } catch (InvalidArgumentException $e) {
            // A scenario with this name already exists (user double-
            // clicked the launchpad). Return the existing id so the
            // caller redirects into it instead of surfacing a 500.
            $existing = $this->existingScenarioIdByName($user, $scenarioName);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
    }

    private function existingScenarioIdByName(User $user, string $name): ?int
    {
        $value = $this->db->connection()->table('forecast_scenarios')
            ->where('user_id', $user->id)
            ->where('name', $name)
            ->value('id');

        return is_numeric($value) ? (int) $value : null;
    }

    private function resolveSeriesName(stdClass $row): string
    {
        if (isset($row->display_name_override) && is_string($row->display_name_override) && $row->display_name_override !== '') {
            return $row->display_name_override;
        }
        if (isset($row->detected_name) && is_string($row->detected_name) && $row->detected_name !== '') {
            return $row->detected_name;
        }

        return 'series';
    }
}
