<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Support\ScenarioSeriesResolver;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreateCancellationScenarioForAlert
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CreateScenario $createScenario,
        private readonly AddScenarioMutation $addMutation,
        private readonly ScenarioSeriesResolver $seriesResolver,
    ) {}

    public function __invoke(int $driftAlertId, User $user): int
    {
        $alert = $this->db->connection()->table('drift_alerts')
            ->where('id', $driftAlertId)
            ->where('user_id', $user->id)
            ->first(['id', 'recurring_series_id']);
        if ($alert === null) {
            throw new NotFoundHttpException('Drift alert not found.');
        }
        /** @var stdClass $alert */
        $seriesId = isset($alert->recurring_series_id) && is_numeric($alert->recurring_series_id)
            ? (int) $alert->recurring_series_id
            : 0;
        if ($seriesId === 0) {
            throw new NotFoundHttpException('Recurring series not found on alert.');
        }

        $series = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first(['id', 'detected_name', 'display_name_override']);
        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }
        /** @var stdClass $series */
        $name = $this->seriesResolver->resolveSeriesName($series);

        $scenarioName = "Cancel {$name}";

        try {
            return $this->db->connection()->transaction(function () use ($user, $scenarioName, $seriesId): int {
                $scenarioId = ($this->createScenario)($user, $scenarioName);
                ($this->addMutation)($scenarioId, $user, ScenarioMutationKind::CancelSeries->value, new CancelSeriesPayload(seriesId: $seriesId));

                return $scenarioId;
            });
        } catch (InvalidArgumentException $e) {
            // A scenario with this name already exists (the user opened
            // the launchpad once, then clicked again without deleting
            // the prior scenario). Return the existing id so the caller
            // redirects into it instead of surfacing a 500.
            $existing = $this->seriesResolver->existingScenarioIdByName($user, $scenarioName);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
    }
}
