<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;

// The wizard's nine steps share one URL, so stepping back is not navigation and
// the platform's back gesture had to be taught to do it: the page names its
// previous step on the element, pushes a history entry, and the bundle's
// popstate handler calls goToStep when the gesture pops it.
//
// That is an Android affordance and only an Android one. The iOS shell leaves
// allowsBackForwardNavigationGestures at its default false, so the gesture
// never fires there and the handler is dead code on an iPhone. The gesture is
// therefore an enhancement, never the way back: whatever declares the attribute
// must also draw a control a finger can find, or half the readers are stranded
// mid-setup with no way back at all.

// Both roots a template can ship from, not just Modules: the wizard lives in a
// module today, and a layout under resources/views could declare the attribute
// tomorrow with nothing here reading it.
/** @return array{blades: list<string>, walked: int} */
function gestureBackedStepWalk(): array
{
    $found = [];
    $walked = 0;

    foreach ([base_path('Modules'), base_path('resources/views')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getPathname(), '.blade.php')) {
                continue;
            }

            $walked++;

            if (str_contains((string) file_get_contents($file->getPathname()), 'data-wizard-previous-step')) {
                $found[] = $file->getPathname();
            }
        }
    }

    sort($found);

    return ['blades' => $found, 'walked' => $walked];
}

/** @return list<string> blades that hand their previous step to the back gesture */
function gestureBackedStepBlades(): array
{
    return gestureBackedStepWalk()['blades'];
}

it('finds the screens that lean on the back gesture', function (): void {
    $walk = gestureBackedStepWalk();

    // Far under the 279 templates the two roots hold. A walk that opened none
    // of them reports no gesture-backed screen, which is the answer a tree with
    // no wizard gives.
    expect($walk['walked'])->toBeGreaterThan(
        100,
        'The walk opened '.$walk['walked'].' templates, which is too few to have read either root.',
    );

    // Vacuously green is the failure mode this whole file exists to prevent:
    // the attribute is what ties the gesture to a screen, and a rename would
    // otherwise leave every assertion below with nothing to check.
    expect($walk['blades'])->not->toBe(
        [],
        'No template hands a previous step to the back gesture, so every case below checks nothing.',
    );
});

/**
 * Why this template's gesture stands alone, or null when a control reaches the
 * same step. Taking a source string so the case below can drive the reader over
 * a planted template rather than re-implementing it.
 */
function gestureBackedStepWithoutAControl(string $source): ?string
{
    // The step the gesture would return to, as the template names it.
    if (preg_match('/data-wizard-previous-step="\{\{\s*(\$[A-Za-z_][A-Za-z0-9_]*)/', $source, $m) !== 1) {
        return 'the attribute names no variable';
    }

    $step = preg_quote($m[1], '/');

    if (preg_match('/wire:click="goToStep\(\'\{\{\s*'.$step.'\s*\}\}\'\)"/', $source) !== 1) {
        return 'nothing but the gesture reaches '.$m[1];
    }

    return null;
}

it('draws a back control that needs no gesture at all', function (): void {
    $missing = [];

    foreach (gestureBackedStepBlades() as $path) {
        $stranded = gestureBackedStepWithoutAControl((string) file_get_contents($path));

        if ($stranded !== null) {
            $missing[] = $path.' ('.$stranded.')';
        }
    }

    expect($missing)->toBe([], implode("\n", [
        'A wizard step hands its previous step to the back gesture and draws no',
        'control for it. On an iPhone that gesture does not exist, so this is a',
        'reader who cannot go back:',
        ...$missing,
    ]));
});

// The reader is the whole of what the rule above enforces, so it is driven over
// a template that strands its reader and one that does not.
it('reports a step reachable only by the gesture, and passes one with a control', function (): void {
    $stranded = '<div data-wizard-previous-step="{{ $previousStep }}"><p>no way back</p></div>';
    $unnamed = '<div data-wizard-previous-step="{{ steps()[0] }}"></div>';
    $reachable = '<div data-wizard-previous-step="{{ $previousStep }}">'
        .'<button type="button" wire:click="goToStep(\'{{ $previousStep }}\')">Back</button></div>';

    expect(gestureBackedStepWithoutAControl($stranded))->toBe('nothing but the gesture reaches $previousStep');
    expect(gestureBackedStepWithoutAControl($unnamed))->toBe('the attribute names no variable');
    expect(gestureBackedStepWithoutAControl($reachable))->toBeNull(
        'A step whose control names the same variable is not stranded, and reading it as one would make the rule unusable.',
    );
});

it('keeps that control on the screen rather than only in the tree', function (): void {
    $hidden = [];

    foreach (gestureBackedStepBlades() as $path) {
        $source = (string) file_get_contents($path);

        foreach (MarkupSource::elements($source, 'button') as $button) {
            if (! str_starts_with((string) $button->attribute('wire:click'), 'goToStep(')) {
                continue;
            }

            foreach (['sr-only', 'hidden', 'invisible'] as $vanishing) {
                if (in_array($vanishing, $button->classes(), true)) {
                    $hidden[] = $path.' ('.$vanishing.')';
                }
            }
        }
    }

    expect($hidden)->toBe([], "A back control announced only to a screen reader is not a way back. Offenders:\n  ".implode("\n  ", $hidden));
});

// The gesture half stays, on top: an Android reader reaches for the system back
// before they reach for anything the page drew, and landing on the deliberately
// dead recovery-codes route is what it did before the handler existed.
it('leaves the gesture wired to the same attribute', function (): void {
    $bundle = (string) file_get_contents(base_path('resources/js/app.js'));

    expect($bundle)->toContain("const ATTRIBUTE = 'data-wizard-previous-step';")
        ->and($bundle)->toContain("window.addEventListener('popstate'")
        ->and($bundle)->toContain("component.call('goToStep', element.getAttribute(ATTRIBUTE));");
});
