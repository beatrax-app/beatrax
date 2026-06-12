<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

/**
 * Canonical ordered list of first-run wizard step keys, plus the
 * per-step skippable predicate. The order this registry returns is the
 * one truth used by every other wizard collaborator: the resume
 * resolver walks it to find the first pending step, the parent
 * SetupWizard advances through it on `next` / `skip`, and the progress
 * dots render against it.
 *
 * Skippable steps are the four connector steps (bank, paypal, card,
 * email) and the optional `budgets` + `tax-country` steps; the wizard's
 * bookend steps (`welcome`, `done`) and the `first-import` step are not
 * skippable. A `skip` call on a non-skippable step is a no-op at the
 * SetupWizard layer.
 */
final class WizardStepRegistry
{
    /** @var list<string> */
    private const STEPS = [
        'welcome',
        'connect-bank',
        'connect-paypal',
        'connect-card',
        'connect-email',
        'first-import',
        'budgets',
        'tax-country',
        'done',
    ];

    /** @var list<string> */
    private const SKIPPABLE = [
        'connect-bank',
        'connect-paypal',
        'connect-card',
        'connect-email',
        'budgets',
        'tax-country',
    ];

    /** @return list<string> */
    public function steps(): array
    {
        return self::STEPS;
    }

    public function isSkippable(string $stepKey): bool
    {
        return in_array($stepKey, self::SKIPPABLE, true);
    }
}
