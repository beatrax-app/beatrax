<?php

declare(strict_types=1);

use Modules\Notifications\Internal\Enums\DeferredNotificationPass;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Tests\Contracts\Support\BackendSourceFiles;

// SensitiveColumnCodec refuses to seal notifications.title/body/params/
// trigger_type in a process holding no app-lock key, and every OS-scheduled
// task and every queue worker is such a process. A trigger raised only there
// and re-derived nowhere does not arrive late; it never arrives at all.

/**
 * @return list<NotificationTrigger>
 */
function triggersAKeylessProcessCanRaise(): array
{
    return array_values(array_filter(
        NotificationTrigger::cases(),
        static fn (NotificationTrigger $trigger): bool => $trigger->reachableWithoutTheKey(),
    ));
}

/**
 * @return array<string, DeferredNotificationPass>
 */
function reDerivationByTrigger(): array
{
    $byTrigger = [];

    foreach (DeferredNotificationPass::cases() as $pass) {
        foreach ($pass->reDerives() as $trigger) {
            expect(array_key_exists($trigger->value, $byTrigger))->toBeFalse(
                "{$trigger->value} is re-derived by two passes; one keyed request would then run both.",
            );
            $byTrigger[$trigger->value] = $pass;
        }
    }

    return $byTrigger;
}

// An aggregate over an empty set passes whatever the predicate says, and both
// halves matter here: a guard seeing no keyless trigger proves nothing, and one
// seeing no keyed trigger is reading a predicate that answers true for anything.
it('is reading a non-empty set on both sides of the question', function (): void {
    expect(NotificationTrigger::cases())->toHaveCount(11);
    expect(triggersAKeylessProcessCanRaise())->not->toBeEmpty();

    $keyed = array_filter(
        NotificationTrigger::cases(),
        static fn (NotificationTrigger $trigger): bool => ! $trigger->reachableWithoutTheKey(),
    );

    expect($keyed)->not->toBeEmpty();
    expect(reDerivationByTrigger())->not->toBeEmpty();
});

it('has a re-derivation for every trigger a keyless process can raise', function (): void {
    $reDerived = reDerivationByTrigger();

    foreach (triggersAKeylessProcessCanRaise() as $trigger) {
        expect(array_key_exists($trigger->value, $reDerived))->toBeTrue(
            "{$trigger->value} can be raised where no key is held and no DeferredNotificationPass re-derives it, so a sealed ledger loses it permanently. Add it to a pass's reDerives() and to the arm that runs it.",
        );
    }
});

// The other direction, so the two declarations cannot drift apart by adding to
// the easier one: a pass claiming a trigger a keyed process always raises is
// replaying work that was never withheld.
it('re-derives nothing that a keyed process is the only raiser of', function (): void {
    foreach (reDerivationByTrigger() as $value => $pass) {
        $trigger = NotificationTrigger::from($value);

        expect($trigger->reachableWithoutTheKey())->toBeTrue(
            "{$pass->value} re-derives {$value}, which reachableWithoutTheKey() says only a keyed process raises.",
        );
    }
});

it('declares a non-empty re-derivation for every pass', function (): void {
    foreach (DeferredNotificationPass::cases() as $pass) {
        expect($pass->reDerives())->not->toBeEmpty("{$pass->value} re-derives nothing, so running it can recover nothing.");
    }
});

// The mark for the uncovered triggers belongs to the writer and to nothing
// else. Moving it back out to the emitters is how four of the eight came to be
// covered and four did not, and a caller cannot forget what it never calls.
it('marks the withheld triggers from the writer alone', function (): void {
    $callers = [];

    foreach (BackendSourceFiles::all() as $path) {
        if (! str_contains((string) file_get_contents($path), '->markWithheld(')) {
            continue;
        }

        $callers[] = str_replace(base_path().'/', '', $path);
    }

    expect($callers)->toBe(['Modules/Notifications/Internal/Support/NotificationWriter.php']);
});
