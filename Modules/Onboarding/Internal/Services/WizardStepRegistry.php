<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

use Modules\Onboarding\Internal\Enums\WizardStepStatus;

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

    // "Prior" is this list's order, so the jump gate lives with the order rather
    // than in the one component that used to hold it: the resume resolver asks
    // the same question when it picks a step to reopen, and a second copy of the
    // rule is a second answer waiting to disagree with this one.
    /**
     * @param  array<string, array{status: string, completed_at: ?string}>  $progress
     */
    public function isReachable(string $stepKey, array $progress): bool
    {
        $targetIndex = array_search($stepKey, self::STEPS, strict: true);
        if ($targetIndex === false) {
            return false;
        }

        for ($i = 0; $i < $targetIndex; $i++) {
            $priorStatus = $progress[self::STEPS[$i]]['status'] ?? WizardStepStatus::Pending->value;
            if ($priorStatus !== WizardStepStatus::Done->value && $priorStatus !== WizardStepStatus::Skipped->value) {
                return false;
            }
        }

        return true;
    }
}
