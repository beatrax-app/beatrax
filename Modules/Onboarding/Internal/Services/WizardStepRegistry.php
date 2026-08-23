<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

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

    // SetupWizard::skip() gates on this list, so a missing key gives a skip button
    // that dispatches and goes nowhere — how first-import shipped once.
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

    // The terminal step, for the callers that need to land on it without
    // naming it: advance() reaches it by walking, mount() has to jump.
    public function lastStep(): string
    {
        return self::STEPS[count(self::STEPS) - 1];
    }

    public function isSkippable(string $stepKey): bool
    {
        return in_array($stepKey, self::SKIPPABLE, true);
    }
}
