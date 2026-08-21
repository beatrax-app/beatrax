<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use RuntimeException;

// Caught separately from a generic RuntimeException so IncrementalScanJob can
// skip its scanning tick when a backfill is mid-flight.
final class InvalidStateTransitionException extends RuntimeException {}
