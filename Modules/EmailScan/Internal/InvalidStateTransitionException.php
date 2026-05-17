<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use RuntimeException;

/**
 * Thrown when InboxScanStateMachine::applyStatus / ::applyRateLimited
 * is asked to perform a transition that is not present in the
 * ALLOWED_TRANSITIONS const map.
 *
 * Catching this sentinel separately from a generic RuntimeException
 * lets the queued-job pipeline distinguish "the state machine
 * rejected this transition" (a programming or race condition) from
 * "a provider call blew up" (a transient infrastructure failure).
 * IncrementalScanJob catches it on the entry-side scanning transition
 * to gracefully skip the hourly tick when a backfill is mid-flight.
 */
final class InvalidStateTransitionException extends RuntimeException {}
