<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Sync\SetupStep;

// isBefore() ranks two steps by their place in ordered(), and array_search()
// answers `false` for a case that list forgot. PHP then compares a bool against
// an int by casting the int, so `false < 2` is `false < true` — true. A case
// missing from ordered() therefore reads as BEFORE almost everything, and the
// progress screen paints a step the device has not reached as already done, at
// every position.
//
// ordered() is hand-maintained beside four cases that are declared elsewhere,
// so the two can drift the moment a fifth arrives. This is the check that
// turns that into a build failure rather than a screen that lies.

it('ranks every case the enum declares', function (): void {
    expect(SetupStep::ordered())->toHaveCount(
        count(SetupStep::cases()),
        'SetupStep::ordered() lists '.count(SetupStep::ordered()).' of '.count(SetupStep::cases())
        .' cases. A case it forgets is ranked `false`, which reads as earlier than the rest.',
    );

    foreach (SetupStep::cases() as $case) {
        expect(in_array($case, SetupStep::ordered(), true))->toBeTrue(
            $case->value.' is declared but never ranked, so isBefore() places it ahead of everything.',
        );
    }
});

it('orders the steps the way the screen walks them', function (): void {
    $ordered = SetupStep::ordered();

    foreach ($ordered as $index => $step) {
        foreach (array_slice($ordered, $index + 1) as $later) {
            expect($step->isBefore($later))->toBeTrue($step->value.' has to rank before '.$later->value);
            expect($later->isBefore($step))->toBeFalse($later->value.' must not rank before '.$step->value);
        }

        expect($step->isBefore($step))->toBeFalse('a step is not before itself');
    }
});
