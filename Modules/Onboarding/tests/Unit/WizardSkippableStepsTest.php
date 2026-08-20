<?php

declare(strict_types=1);

use Modules\Onboarding\Internal\Services\WizardStepRegistry;

// SetupWizard::skip() returns early on a non-skippable step, so a step can
// render a button, dispatch, round-trip 200 OK and go nowhere — which is what
// first-import did. The button and the registry entry are two halves of one
// feature, and this file is what ties them together.

it('lets the user past first-import, which has no other way forward', function (): void {
    expect((new WizardStepRegistry)->isSkippable('first-import'))->toBeTrue();
});

it('keeps welcome and done unskippable', function (): void {
    // Entry and exit: skipping either is meaningless, not merely unwanted.
    $registry = new WizardStepRegistry;

    expect($registry->isSkippable('welcome'))->toBeFalse()
        ->and($registry->isSkippable('done'))->toBeFalse();
});

it('names only steps that actually exist', function (): void {
    // A typo'd key makes a step silently unskippable forever.
    $registry = new WizardStepRegistry;

    foreach ($registry->steps() as $step) {
        expect($registry->isSkippable($step))->toBeBool();
    }

    expect($registry->isSkippable('not-a-step'))->toBeFalse();
});
