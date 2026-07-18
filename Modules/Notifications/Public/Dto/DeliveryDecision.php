<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Dto;

/**
 * The outcome of a single `SuppressionEvaluator::shouldDeliver()` call.
 *
 * Lives on `Public\Dto` (relocated from `Internal\Support` during 18-11)
 * alongside `SuppressionEvaluator` itself — a cross-module delivery adapter
 * that can call the method still needs to see its return type.
 *
 * A decision object rather than a bare bool (locked planner decision): the
 * delivery adapters need to know WHY delivery was suppressed for their logs
 * (`$reason`), and the D-24 "hide details" outcome rides along on every
 * decision so an adapter can never forget to consult it.
 *
 * `$reason` is one of `ok` (delivered), `trigger_disabled` (per-trigger
 * toggle off, D-08), `quiet_hours` (inside the device's quiet window,
 * D-19), or `seeding` (the D-43 store-but-do-not-deliver flag).
 *
 * `$hideDetails` always reflects the device's `hide_details` preference
 * (D-24) regardless of the `$deliver` outcome.
 */
final readonly class DeliveryDecision
{
    public function __construct(
        public bool $deliver,
        public string $reason,
        public bool $hideDetails,
    ) {}
}
