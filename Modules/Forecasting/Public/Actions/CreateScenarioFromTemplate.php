<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Enums\ScenarioTemplate;
use Modules\Forecasting\Internal\Support\ScenarioSeriesResolver;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class CreateScenarioFromTemplate
{
    public function __construct(
        private DatabaseManager $db,
        private CreateScenario $createScenario,
        private AddScenarioMutation $addMutation,
        private ScenarioSeriesResolver $seriesResolver,
    ) {}

    public function __invoke(
        ScenarioTemplate $template,
        int $recurringSeriesId,
        User $user,
        ?int $newAmountMinor = null,
    ): int {
        $series = $this->seriesRow($recurringSeriesId, $user);

        // Asked before the insert, not only after it fails: the reader who
        // clicks the launchpad twice gets the scenario they already made,
        // and a name that is theirs to rename never breaks that.
        $existing = $this->seriesResolver->existingScenarioIdForTemplate($user, $template, $recurringSeriesId);
        if ($existing !== null) {
            return $existing;
        }

        $scenarioName = Lang::get($template->nameKey(), [
            'name' => $this->seriesResolver->resolveSeriesName($series),
        ]);
        $payload = $template->payloadFor($recurringSeriesId, $newAmountMinor);

        try {
            return $this->db->connection()->transaction(function () use ($user, $scenarioName, $payload): int {
                $scenarioId = ($this->createScenario)($user, $scenarioName);
                ($this->addMutation)($scenarioId, $user, $payload->kind(), $payload);

                return $scenarioId;
            });
        } catch (InvalidArgumentException $e) {
            // The name is taken by a scenario this template did not write —
            // one the reader named themselves. Handing that one back is what
            // this did before there was a template key, so it still does.
            $named = $this->seriesResolver->existingScenarioIdByName($user, $scenarioName);
            if ($named !== null) {
                return $named;
            }
            throw $e;
        }
    }

    // The drift page holds an alert id, not a series id, and may not name this
    // module's template vocabulary: a cancellation is the only what-if it
    // offers, so it asks for that one by name.
    public function forDriftAlert(int $driftAlertId, User $user): int
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

        return $this(ScenarioTemplate::Cancel, $seriesId, $user);
    }

    private function seriesRow(int $recurringSeriesId, User $user): stdClass
    {
        $series = $this->db->connection()->table('recurring_series')
            ->where('id', $recurringSeriesId)
            ->where('user_id', $user->id)
            ->first(['id', 'detected_name', 'display_name_override']);
        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }

        /** @var stdClass $series */
        return $series;
    }
}
