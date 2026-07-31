<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\StateMachines;

use RuntimeException;

// Thrown inside the DriftAlertStateMachine transaction when the
// lockForUpdate row lookup returns null — the drift_alerts row vanished
// mid-flight after the caller handed us the model.
/**
 * @link ../../../../.docs/features/drift-alerts/architecture.md
 */
final class DriftAlertNotFoundException extends RuntimeException {}
