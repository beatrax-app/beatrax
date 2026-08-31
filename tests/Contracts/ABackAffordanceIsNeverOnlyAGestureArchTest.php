<?php

declare(strict_types=1);

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

/** @return list<string> blades that hand their previous step to the back gesture */
function gestureBackedStepBlades(): array
{
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getPathname(), '.blade.php')) {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'data-wizard-previous-step')) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

it('finds the screens that lean on the back gesture', function (): void {
    // Vacuously green is the failure mode this whole file exists to prevent:
    // the attribute is what ties the gesture to a screen, and a rename would
    // otherwise leave every assertion below with nothing to check.
    expect(gestureBackedStepBlades())->not->toBe([]);
});

it('draws a back control that needs no gesture at all', function (): void {
    $missing = [];

    foreach (gestureBackedStepBlades() as $path) {
        $source = (string) file_get_contents($path);

        // The step the gesture would return to, as the template names it.
        if (preg_match('/data-wizard-previous-step="\{\{\s*(\$[A-Za-z_][A-Za-z0-9_]*)/', $source, $m) !== 1) {
            $missing[] = $path.' (the attribute names no variable)';

            continue;
        }

        $step = preg_quote($m[1], '/');

        if (preg_match('/wire:click="goToStep\(\'\{\{\s*'.$step.'\s*\}\}\'\)"/', $source) !== 1) {
            $missing[] = $path.' (nothing but the gesture reaches '.$m[1].')';
        }
    }

    expect($missing)->toBe([], implode("\n", [
        'A wizard step hands its previous step to the back gesture and draws no',
        'control for it. On an iPhone that gesture does not exist, so this is a',
        'reader who cannot go back:',
        ...$missing,
    ]));
});

it('keeps that control on the screen rather than only in the tree', function (): void {
    $hidden = [];

    foreach (gestureBackedStepBlades() as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/<button\b[^>]*?wire:click="goToStep\([^>]*?>/s', $source, $m) !== 1) {
            continue;
        }

        if (preg_match('/class="([^"]*)"/', $m[0], $classes) !== 1) {
            continue;
        }

        foreach (['sr-only', 'hidden', 'invisible'] as $vanishing) {
            if (in_array($vanishing, preg_split('/\s+/', trim($classes[1])) ?: [], true)) {
                $hidden[] = $path.' ('.$vanishing.')';
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
