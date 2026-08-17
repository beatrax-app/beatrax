<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

/**
 * @link ../../../../.docs/features/onboarding/architecture.md
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

    /*
     * Which steps may be passed without doing them.
     *
     * `first-import` belongs here because its own control is disabled whenever
     * no section is ready — the ordinary state for someone who skipped the
     * connectors. Without it the step has no enabled way forward at all, and
     * budgets, tax-country and done sit behind it unreachable.
     *
     * This list is the gate SetupWizard::skip() checks first, so a step that
     * renders a skip button and dispatches wizard.step.skipped still goes
     * nowhere unless its key appears here. That is exactly how first-import
     * came to have a working button attached to a no-op.
     */
    /** @var list<string> */
    private const SKIPPABLE = [
        'connect-bank',
        'connect-paypal',
        'connect-card',
        'connect-email',
        'first-import',
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
