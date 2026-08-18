<?php

declare(strict_types=1);

use Modules\Onboarding\Internal\Services\WizardStepRegistry;

/*
 * Every step that offers a skip must be skippable in the registry.
 *
 * SetupWizard::skip() opens with `if (! $registry->isSkippable(...)) return;`,
 * so a step can render a working button, dispatch wizard.step.skipped, round-
 * trip 200 OK, and go nowhere — which is exactly what `first-import` did. The
 * button and the registry entry are two halves of one feature and nothing tied
 * them together, so this test is the tie.
 *
 * `first-import` matters most: its own commit control is disabled whenever no
 * section is ready, which is the ordinary state for someone who skipped the
 * connectors. Unskippable, it strands budgets, tax-country and done behind it.
 */

it('lets the user past first-import, which has no other way forward', function (): void {
    expect((new WizardStepRegistry)->isSkippable('first-import'))->toBeTrue();
});

it('keeps welcome and done unskippable', function (): void {
    // Welcome is the entry and done is the exit; skipping either is meaningless
    // rather than merely unwanted.
    $registry = new WizardStepRegistry;

    expect($registry->isSkippable('welcome'))->toBeFalse()
        ->and($registry->isSkippable('done'))->toBeFalse();
});

it('names only steps that actually exist', function (): void {
    // A typo'd key would silently make a step unskippable forever, which is the
    // failure this whole test file exists to catch.
    $registry = new WizardStepRegistry;

    foreach ($registry->steps() as $step) {
        expect($registry->isSkippable($step))->toBeBool();
    }

    expect($registry->isSkippable('not-a-step'))->toBeFalse();
});
