<?php

declare(strict_types=1);

namespace Modules\Onboarding\Public\Events;

/**
 * Dispatched when a user finishes the first-run setup wizard — either by
 * completing the final step or by accepting the "Skip the rest" dismiss.
 * Listeners (e.g. analytics-shaped local telemetry, post-wizard
 * navigation hooks) consume the event via the per-user `$userId`
 * payload; the event carries no other state because the wizard's own
 * `wizard_progress` rows hold the per-step completion data.
 */
final class WizardCompleted
{
    public function __construct(public readonly int $userId) {}
}
