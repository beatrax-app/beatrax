<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

final class WizardStepRegistry
{
    /** @var list<string> */
    private const array STEPS = [
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
    private const array SKIPPABLE = [
        'connect-bank',
        'connect-paypal',
        'connect-card',
        'connect-email',
        'first-import',
        'budgets',
        'tax-country',
    ];

    // The footer pill promises the reader that their data stays put, and a step
    // whose own primary action opens a connection to somebody else cannot carry
    // that line: "Your data stays on this device" sat under "Authorize with
    // Gmail". Declared here so the footer asks per step rather than per page.
    /** @var list<string> */
    private const array REACHES_A_THIRD_PARTY = [
        'connect-email',
    ];

    /** @return list<string> */
    public function steps(): array
    {
        return self::STEPS;
    }

    public function reachesAThirdParty(string $stepKey): bool
    {
        return in_array($stepKey, self::REACHES_A_THIRD_PARTY, true);
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
