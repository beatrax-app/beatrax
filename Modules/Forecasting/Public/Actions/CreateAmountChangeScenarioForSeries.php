<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Support\ScenarioSeriesResolver;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CreateAmountChangeScenarioForSeries
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CreateScenario $createScenario,
        private readonly AddScenarioMutation $addMutation,
        private readonly ScenarioSeriesResolver $seriesResolver,
    ) {}

    public function __invoke(int $recurringSeriesId, User $user, int $newAmountMinor): int
    {
        $series = $this->db->connection()->table('recurring_series')
            ->where('id', $recurringSeriesId)
            ->where('user_id', $user->id)
            ->first(['id', 'detected_name', 'display_name_override']);
        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }
        /** @var stdClass $series */
        $name = $this->seriesResolver->resolveSeriesName($series);

        $scenarioName = "Change {$name} amount";

        try {
            return $this->db->connection()->transaction(function () use ($user, $scenarioName, $recurringSeriesId, $newAmountMinor): int {
                $scenarioId = ($this->createScenario)($user, $scenarioName);
                ($this->addMutation)(
                    $scenarioId,
                    $user,
                    ScenarioMutationKind::ChangeSeriesAmount->value,
                    new ChangeSeriesAmountPayload(seriesId: $recurringSeriesId, newAmountMinor: $newAmountMinor),
                );

                return $scenarioId;
            });
        } catch (InvalidArgumentException $e) {
            // A scenario with this name already exists (user double-
            // clicked the launchpad). Return the existing id so the
            // caller redirects into it instead of surfacing a 500 —
            // the existing mutation's amount is NOT updated.
            $existing = $this->seriesResolver->existingScenarioIdByName($user, $scenarioName);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
    }
}
