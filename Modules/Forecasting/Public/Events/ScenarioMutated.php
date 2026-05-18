<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Events;

/**
 * Dispatched after any state-changing operation against a saved
 * scenario other than creation / deletion: a mutation was added,
 * removed, or edited, OR the scenario itself was renamed. Carries the
 * mutation id when applicable and the kind string so downstream
 * listeners can decide whether the change requires a re-projection.
 *
 * The `mutationId` is `0` (sentinel) for events that originate from a
 * `RenameScenario` invocation — the row's `kind` field is set to the
 * literal string `'rename'` in that case so downstream consumers can
 * tell the two surfaces apart. Wave 4 reuses this event for rename
 * because the projection-pipeline trigger is identical; a later wave
 * may split the rename surface into its own dedicated event if any
 * listener needs to differentiate the cases.
 */
final readonly class ScenarioMutated
{
    public function __construct(
        public int $userId,
        public int $scenarioId,
        public int $mutationId,
        public string $kind,
    ) {}
}
