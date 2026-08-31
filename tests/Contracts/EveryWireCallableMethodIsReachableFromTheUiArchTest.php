<?php

declare(strict_types=1);

use Modules\Community\Internal\Http\Livewire\SharedListSettingsPanel;
use Tests\Contracts\Fixtures\Livewire\SyntheticUnreachableActionViolator;
use Tests\Contracts\Support\WireCallableMethods;

/**
 * @return list<string>
 */
function unreachableWireActions(): array
{
    // An entry is justified only when the method must stay public AND must
    // stay callerless. "Nothing calls it yet" is the defect, not the excuse.
    /** @var array<class-string, list<string>> $allowList */
    $allowList = [
        SharedListSettingsPanel::class => [
            // The disabled checkbox is only the user-facing speed bump; this
            // no-op is what stops a forged Livewire call writing the column,
            // so its having no caller is the whole point of it.
            'toggleUpdateOnAppUpdates',
        ],
    ];

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

/**
 * @link ../../.docs/conventions/a-public-livewire-method-is-a-public-endpoint.md
 */
it('leaves no public Livewire method the UI cannot reach', function (): void {
    $offenders = unreachableWireActions();

    expect($offenders)->toBe([], sprintf(
        "Livewire dispatches by method name, so a public method no control reaches is both a feature\n".
        "no reader can use and an endpoint a crafted request can call. Wire it, make it private, or\n".
        "allow-list it with the reason it must stay public and callerless:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

// The scan is generous on purpose, and a guard that cries wolf is a guard
// nobody reads. Each of these is reached by something that does not read like
// a call, and every one of them was reported by a first draft of the scan.

it('does not mistake a caller a grep cannot see for an absent one', function (): void {
    $offenders = implode("\n", unreachableWireActions());

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
        expect($offenders)->not->toContain(
            $reached,
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
