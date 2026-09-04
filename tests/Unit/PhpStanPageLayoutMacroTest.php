<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

// `-d memory_limit=2G` on the child, not just PHPStan's own
// `--memory-limit=1G`: the runtime bounds the heap at the inherited 128M
// before PHPStan ever parses its flag.
function pageLayoutFixtureAnalysis(string $fixture): string
{
    $process = new Process([
        PHP_BINARY,
        '-d', 'memory_limit=2G',
        base_path('vendor/bin/phpstan'),
        'analyse',
        '--configuration='.base_path('phpstan-fixtures.neon'),
        base_path('app/PhpStan/Rules/Fixtures/'.$fixture),
        '--no-progress',
        '--error-format=raw',
        '--no-ansi',
        '--memory-limit=1G',
    ], base_path());
    // Symfony's 60-second default assumes this analysis has the machine to
    // itself; under pcov and parallel workers it fails on the clock instead of
    // on the signature it exists to pin.
    $process->setTimeout(600);
    $process->run();

    return $process->getOutput().$process->getErrorOutput();
}

it('resolves the extends() macro Livewire registers on the view contract', function (): void {
    expect(pageLayoutFixtureAnalysis('GoodPageLayoutFixture.php'))
        ->not->toContain('Illuminate\Contracts\View\View::extends()');
});

it('still types both parameters of the macro it declared', function (): void {
    $output = pageLayoutFixtureAnalysis('BadPageLayoutFixture.php');

    expect($output)
        ->toContain('Parameter #1 $view of method Illuminate\Contracts\View\View::extends() expects string, int given.')
        ->and($output)
        ->toContain('Parameter #2 $params of method Illuminate\Contracts\View\View::extends() expects array<string, mixed>, string given.');
});

// The failure this pins is an extension that answers yes to everything: with
// six of Livewire's seven macros undeclared, one of them is the control.
it('leaves the Livewire macros it did not declare undefined', function (): void {
    expect(pageLayoutFixtureAnalysis('BadPageLayoutFixture.php'))
        ->toContain('Call to an undefined method Illuminate\Contracts\View\View::layout().');
});
