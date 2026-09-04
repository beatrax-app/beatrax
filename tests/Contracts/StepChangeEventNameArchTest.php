<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-step-change-announced-under-a-name-nothing-listens-for
 */
const STEP_CHANGED_EVENT_NAME = 'step-changed';

/**
 * A dispatch names its event through a constant, so scanning the call sites
 * would read `self::STEP_CHANGED_EVENT` and learn nothing. The literal is what
 * is scanned instead, wherever it is written — a second constant declaring a
 * second spelling is exactly the drift this catches.
 *
 * @param  list<string>  $paths
 * @return list<string> one entry per step change spelled another way
 */
function stepChangeNamesByAnotherSpelling(array $paths): array
{
    $hits = [];

    foreach ($paths as $path) {
        // The list is enumerated before it is read, so a file deleted in
        // between is a file this rule has nothing to say about.
        if (! is_file($path)) {
            continue;
        }

        if (str_ends_with($path, '.blade.php')) {
            $hits = [...$hits, ...stepChangeNamesInMarkup($path)];

            continue;
        }

        foreach (BackendSourceFiles::codeTokens($path) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $name = trim($token[1], "'\"");
            if (stepChangeMeansTheSameThing($name)) {
                $hits[] = "{$path}:{$token[2]} spells it '{$name}'";
            }
        }
    }

    return $hits;
}

/** @return list<string> */
function stepChangeNamesInMarkup(string $path): array
{
    // Blade is not tokenizable as PHP, and a directive can carry an event name
    // in an attribute as easily as in an @php block.
    $contents = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
    $hits = [];

    $matches = PatternScan::all('/[\'"]([A-Za-z0-9_.:-]+)[\'"]/', $contents);

    foreach (array_unique($matches[1]) as $name) {
        if (stepChangeMeansTheSameThing($name)) {
            $hits[] = "{$path} spells it '{$name}'";
        }
    }

    return $hits;
}

/**
 * A name that says a step changed, spelled any way other than the one the
 * bundle binds. `wizard.step.completed` and its siblings travel the other way
 * — a step telling its parent it is done — and say nothing about a change.
 */
function stepChangeMeansTheSameThing(string $name): bool
{
    if ($name === STEP_CHANGED_EVENT_NAME) {
        return false;
    }

    return preg_match('/step/i', $name) === 1 && preg_match('/chang/i', $name) === 1;
}

/** @return list<string> backend PHP plus every Blade template */
function stepChangeScannedFiles(): array
{
    $blades = [];

    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
                $blades[] = $file->getPathname();
            }
        }
    }

    sort($blades);

    return [...BackendSourceFiles::all(), ...$blades];
}

it('announces every step change under the one name the bundle listens for', function (): void {
    $files = stepChangeScannedFiles();
    expect($files)->not->toBeEmpty();

    expect(stepChangeNamesByAnotherSpelling($files))->toBe([], implode("\n", [
        'A step change announced under any other name reaches no listener, and the only',
        'symptom is a screen that opens already scrolled — which reads as a layout bug',
        'anywhere except a device. Announce it through AnnouncesStepChanges. Offenders:',
    ]));
});

it('binds that exact name once, in the bundle rather than on a screen', function (): void {
    // The other half of the pair: the guard above is only worth something while
    // something still moves the viewport when the name arrives.
    $bundle = (string) file_get_contents(base_path('resources/js/app.js'));

    expect($bundle)->toContain("addEventListener('".STEP_CHANGED_EVENT_NAME."'")
        ->and($bundle)->toContain('window.scrollTo({ top: 0 })');
});

it('writes that name in exactly one place on the server side', function (): void {
    $spelled = [];

    foreach (BackendSourceFiles::all() as $path) {
        if (is_file($path) && str_contains((string) file_get_contents($path), "'".STEP_CHANGED_EVENT_NAME."'")) {
            $spelled[] = str_replace(base_path().'/', '', $path);
        }
    }

    // A second literal is how the pair drifts: one of them gets renamed.
    expect($spelled)->toBe(['Modules/Core/Public/Http/Livewire/Concerns/AnnouncesStepChanges.php']);
});

it('sees a step change announced under a near-miss name', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'step-change-name').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedStepDispatch
        {
            public function run(): void
            {
                $this->dispatch('step-changed');
                $this->dispatch('wizard-step-changed');
                $this->dispatch('wizard.step.completed');
                $this->dispatch('theme-changed');
            }
        }
        PHP);

    try {
        $found = stepChangeNamesByAnotherSpelling([$planted]);
    } finally {
        @unlink($planted);
    }

    expect($found)->toHaveCount(1);
    expect($found[0])->toContain("spells it 'wizard-step-changed'");
});
