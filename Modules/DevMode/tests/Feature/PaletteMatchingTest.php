<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Http\Livewire\CommandPaletteModal;
use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Symfony\Component\Process\Process;

// The palette filters in the browser, so the only honest way to test what a
// user sees is to run the shipped module. The probe under tests/Fixtures feeds
// it a registry and reports the order it ranks the rows in; everything the
// assertions care about stays here.

function paletteMatchRepoRoot(): string
{
    // Walked from this file rather than from base_path(): the skip below is
    // decided while tests are collected, before an application exists, and
    // mobile-app/ is a second composer root whose base path is not this tree.
    return dirname((string) realpath(__DIR__), 4);
}

function paletteMatchIsRunnable(): bool
{
    $root = paletteMatchRepoRoot();

    return is_dir($root.'/node_modules/fuse.js')
        && is_file($root.'/Modules/DevMode/tests/Fixtures/palette-match-probe.mjs');
}

/**
 * @param  list<array<string, mixed>>  $registry
 * @param  list<string>  $queries
 * @return array<string, list<string>>
 */
function paletteMatchLabels(array $registry, array $queries): array
{
    $root = paletteMatchRepoRoot();

    $process = new Process(['node', $root.'/Modules/DevMode/tests/Fixtures/palette-match-probe.mjs'], $root);
    $process->setInput((string) json_encode(['registry' => $registry, 'queries' => $queries]));
    $process->run();

    expect($process->isSuccessful())->toBeTrue('the palette matcher probe failed: '.$process->getErrorOutput());

    $decoded = json_decode($process->getOutput(), true);
    expect($decoded)->toBeArray();

    /** @var array<string, list<string>> $decoded */
    return $decoded;
}

/**
 * @return list<array<string, mixed>>
 */
function paletteMatchDeveloperRegistry(): array
{
    $user = User::query()->create([
        'username' => 'palette-match-dev',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);

    /** @var CommandPaletteModal $component */
    $component = Livewire::actingAs($user)->test(CommandPaletteModal::class);

    return $component->instance()->buildRegistry(
        app(CurrentUser::class),
        app(NavigationRegistry::class),
        app(DevCommandRegistry::class),
        app(AppActionRegistry::class),
    );
}

it('ranks the destination a user named above everything the letters also happen to fit', function (): void {
    $registry = paletteMatchDeveloperRegistry();

    // A developer's palette is the crowded one — every dev row and safe
    // command competes with the destinations — and an empty registry would
    // satisfy every expectation below.
    expect(count($registry))->toBeGreaterThan(40);

    $wanted = [
        // The word, whole.
        'budgets' => 'Budgets',
        'reconcile' => 'Reconcile',
        'notifications' => 'Notifications',
        'cash book' => 'Cash book',
        // The singular of a plural label, and the plural of a singular one.
        'budget' => 'Budgets',
        'pot' => 'Pots',
        'goal' => 'Goals',
        'report' => 'Reports',
        // A partial, typed a couple of letters in.
        'go' => 'Goals',
        'un' => 'Unusual charges',
        'co' => 'Community',
        'recon' => 'Reconcile',
        'sub' => 'Subscriptions',
        // A word from inside a two-word label.
        'charges' => 'Unusual charges',
        'devices' => 'Data & Devices',
    ];

    $answers = paletteMatchLabels($registry, array_keys($wanted));

    $wrong = [];
    foreach ($wanted as $query => $expected) {
        $top = $answers[$query][0] ?? '(nothing)';
        if ($top !== $expected) {
            $wrong[] = '"'.$query.'" ranks ['.$top.'] first, wanted ['.$expected.']';
        }
    }

    expect($wrong)->toBe([], "The palette answers a typed word with the wrong destination:\n  ".implode("\n  ", $wrong));
})->skip(! paletteMatchIsRunnable(), 'node_modules is not installed, so the palette module cannot be run.');

// The reported symptom, kept as its own case because it is the one a reader of
// the bug will look for: searching Pots surfaced Imports and nothing else.
it('answers Pots with Pots rather than Imports', function (): void {
    $answers = paletteMatchLabels(paletteMatchDeveloperRegistry(), ['Pots']);

    expect($answers['Pots'][0] ?? '(nothing)')->toBe('Pots');
})->skip(! paletteMatchIsRunnable(), 'node_modules is not installed, so the palette module cannot be run.');

it('opens on the whole roster when nothing has been typed', function (): void {
    $registry = paletteMatchDeveloperRegistry();
    $answers = paletteMatchLabels($registry, ['']);

    expect($answers[''])->toHaveCount(count($registry));
})->skip(! paletteMatchIsRunnable(), 'node_modules is not installed, so the palette module cannot be run.');

// Twenty-six locales, and more than half of them label a destination with a
// letter the reader's keyboard reaches through a dead key or not at all.
it('folds accents so a label can be found by typing it plainly', function (): void {
    $registry = [
        ['id' => 'budgets.index', 'label' => 'Rozpočty', 'hint' => 'Obálkové rozpočty podle kategorie', 'keywords' => []],
        ['id' => 'pots.index', 'label' => 'Spořicí obálky', 'hint' => 'Peníze odložené stranou', 'keywords' => []],
        ['id' => 'imports.new', 'label' => 'Importy', 'hint' => 'Nahrání bankovních výpisů', 'keywords' => []],
    ];

    $answers = paletteMatchLabels($registry, ['rozpocty', 'rozpoc', 'sporici']);

    expect($answers['rozpocty'][0] ?? '(nothing)')->toBe('Rozpočty');
    expect($answers['rozpoc'][0] ?? '(nothing)')->toBe('Rozpočty');
    expect($answers['sporici'][0] ?? '(nothing)')->toBe('Spořicí obálky');
})->skip(! paletteMatchIsRunnable(), 'node_modules is not installed, so the palette module cannot be run.');
