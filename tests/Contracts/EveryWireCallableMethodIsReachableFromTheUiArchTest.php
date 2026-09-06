<?php

declare(strict_types=1);

use Modules\Community\Internal\Http\Livewire\SharedListSettingsPanel;
use Tests\Contracts\Fixtures\Livewire\SyntheticUnreachableActionViolator;
use Tests\Contracts\Support\WireCallableMethods;

// An entry is justified only when the method must stay public AND must stay
// callerless. "Nothing calls it yet" is the defect, not the excuse. Held to a
// method that would otherwise be reported, below, so an entry that stops
// excusing anything is deleted rather than left reading as considered.
/** @var array<class-string, list<string>> */
const UNREACHABLE_WIRE_ACTION_ALLOW_LIST = [
    SharedListSettingsPanel::class => [
        // The disabled checkbox is only the user-facing speed bump; this
        // no-op is what stops a forged Livewire call writing the column,
        // so its having no caller is the whole point of it.
        'toggleUpdateOnAppUpdates',
    ],
];

/**
 * @param  array<class-string, list<string>>  $allowList
 * @return list<string>
 */
function unreachableWireActions(array $allowList): array
{
    $reachable = WireCallableMethods::namesTemplatesCanReach() + WireCallableMethods::namesProductionPhpReaches();

    $offenders = [];

    foreach (WireCallableMethods::components() as $component) {
        foreach (WireCallableMethods::invokableOn($component) as $method) {
            $name = $method->getName();

            if (isset($reachable[$name]) || in_array($name, $allowList[$component] ?? [], true)) {
                continue;
            }

            $offenders[] = sprintf(
                '%s::%s() — %s:%d',
                $component,
                $name,
                str_replace(base_path().'/', '', (string) $method->getFileName()),
                $method->getStartLine(),
            );
        }
    }

    sort($offenders);

    return $offenders;
}

// Three denominators, because the verdict below is a list that is empty over a
// clean tree and empty over a walk that resolved no component, reflected no
// method, or read no caller. The floors sit far under today's 157 components
// and the names two walks find between them.
it('reads the component tree, the methods on it, and the callers of them', function (): void {
    expect(count(WireCallableMethods::components()))->toBeGreaterThan(
        50,
        'the walk resolved '.count(WireCallableMethods::components()).' Livewire components, which is too few to be this tree.'
    );

    $invokable = 0;

    foreach (WireCallableMethods::components() as $component) {
        $invokable += count(WireCallableMethods::invokableOn($component));
    }

    expect($invokable)->toBeGreaterThan(
        100,
        'reflection found '.$invokable.' invokable methods across every component, which is too few to be this tree.'
    );

    expect(count(WireCallableMethods::namesTemplatesCanReach()))->toBeGreaterThan(
        100,
        'the template walk found no method name a control reaches, so every method reads as unreachable.'
    );

    expect(count(WireCallableMethods::namesProductionPhpReaches()))->toBeGreaterThan(
        100,
        'the PHP walk found no method name a caller reaches, so every method reads as unreachable.'
    );
});

/**
 * @link ../../.docs/conventions/a-public-livewire-method-is-a-public-endpoint.md
 */
it('leaves no public Livewire method the UI cannot reach', function (): void {
    $offenders = unreachableWireActions(UNREACHABLE_WIRE_ACTION_ALLOW_LIST);

    expect($offenders)->toBe([], sprintf(
        "Livewire dispatches by method name, so a public method no control reaches is both a feature\n".
        "no reader can use and an endpoint a crafted request can call. Wire it, make it private, or\n".
        "allow-list it with the reason it must stay public and callerless:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

// An allow-list entry that excuses nothing reads as a decision and does
// nothing, and the method it names has usually been wired or deleted since.
it('still holds every allow-list entry to a method the scan would otherwise report', function (): void {
    $unallowed = implode("\n", unreachableWireActions([]));

    $dead = [];

    foreach (UNREACHABLE_WIRE_ACTION_ALLOW_LIST as $component => $methods) {
        foreach ($methods as $method) {
            if (! str_contains($unallowed, $component.'::'.$method.'()')) {
                $dead[] = $component.'::'.$method;
            }
        }
    }

    expect($dead)->toBe([], implode("\n", [
        'These are allow-listed and the scan does not report them anyway — they are reached, or gone:',
        ...$dead,
        '',
        'Delete the entry. An exemption whose subject has moved is the shape that lets the next one',
        'through unread.',
    ]));
});

// The scan is generous on purpose, and a guard that cries wolf is a guard
// nobody reads. Each of these is reached by something that does not read like
// a call, and every one of them was reported by a first draft of the scan.

it('does not mistake a caller a grep cannot see for an absent one', function (): void {
    $offenders = implode("\n", unreachableWireActions(UNREACHABLE_WIRE_ACTION_ALLOW_LIST));

    $reachedAnyway = [
        // x-on:click="$wire.markRead()" on the row anchor.
        'NotificationsPage::markRead',
        // A toastWithUndo(undoAction: …) payload the toast host calls back.
        'DriftPage::undoAnomalySuppression',
        // A protected $listeners array rather than #[On].
        'ForecastPage::onBufferSaved',
        'ForecastPage::onScenarioMutated',
        'ForecastPage::onScenarioDeleted',
        // Called by the component's own render().
        'TransactionsList::isSearchActive',
        // Called by the component's own toggleClicked / reconfirmEnable.
        'OpenBankingSettingsPage::requestEnable',
        'OpenBankingSettingsPage::enableOpenBanking',
    ];

    foreach ($reachedAnyway as $reached) {
        expect(str_contains($offenders, $reached))->toBeFalse(
            $reached.' IS reached, by something that does not read like a call. Reporting it is the scan being wrong.',
        );
    }
});

it('catches a synthetic violator living outside the production tree', function (): void {
    $names = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        WireCallableMethods::invokableOn(SyntheticUnreachableActionViolator::class),
    );

    expect($names)->toBe(['wipeEverythingSyntheticallyUnreachable']);

    $reachable = WireCallableMethods::namesTemplatesCanReach() + WireCallableMethods::namesProductionPhpReaches();

    expect($reachable)->not->toHaveKey('wipeEverythingSyntheticallyUnreachable');
});
