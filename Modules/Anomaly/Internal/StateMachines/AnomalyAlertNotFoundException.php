<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\StateMachines;

use RuntimeException;

// The anomaly_alerts row vanished between the caller loading the model and the
// state machine's lockForUpdate — a cascade delete from a deleted transaction.
final class AnomalyAlertNotFoundException extends RuntimeException {}
