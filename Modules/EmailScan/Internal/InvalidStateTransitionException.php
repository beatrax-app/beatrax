<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use RuntimeException;

// Thrown when InboxScanStateMachine is asked for a transition absent
// from ALLOWED_TRANSITIONS; caught separately from a generic
// RuntimeException so IncrementalScanJob can gracefully skip its
// entry-side scanning tick when a backfill is mid-flight.
final class InvalidStateTransitionException extends RuntimeException {}
