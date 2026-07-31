<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\StateMachines;

use RuntimeException;

// Thrown inside the AnomalyAlertStateMachine transaction when the
// lockForUpdate row lookup returns null — the anomaly_alerts row vanished
// mid-flight (a cascade delete from a deleted transaction) after the
// caller handed us the model.
/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final class AnomalyAlertNotFoundException extends RuntimeException {}
