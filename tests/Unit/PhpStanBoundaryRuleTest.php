<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

// `-d memory_limit=2G` on the child, not just PHPStan's own
// `--memory-limit=1G`: the runtime bounds the heap at the inherited 128M
// before PHPStan ever parses its flag, so the Modules walk OOMs first.
it('emits a BoundaryRule error on the bad fixture', function (): void {
    $process = new Process([
        PHP_BINARY,
        '-d', 'memory_limit=2G',
        base_path('vendor/bin/phpstan'),
        'analyse',
        '--configuration='.base_path('phpstan-fixtures.neon'),
        base_path('app/PhpStan/Rules/Fixtures/BadBoundaryFixture.php'),
        '--no-progress',
        '--error-format=raw',
        '--no-ansi',
        '--memory-limit=1G',
    ], base_path());
    // Symfony's 60-second default assumes this analysis has the machine to
    // itself; under pcov and parallel workers it fails on the clock instead of
    // on the rule it exists to pin.
    $process->setTimeout(600);
    $process->run();

    $output = $process->getOutput().$process->getErrorOutput();
    expect($output)->toContain('Cross-module Internal/Models import forbidden');
});

it('emits zero errors on the good fixture', function (): void {
    $process = new Process([
        PHP_BINARY,
        '-d', 'memory_limit=2G',
        base_path('vendor/bin/phpstan'),
        'analyse',
        '--configuration='.base_path('phpstan-fixtures.neon'),
        base_path('app/PhpStan/Rules/Fixtures/GoodBoundaryFixture.php'),
        '--no-progress',
        '--error-format=raw',
        '--no-ansi',
        '--memory-limit=1G',
    ], base_path());
    // Symfony's 60-second default assumes this analysis has the machine to
    // itself; under pcov and parallel workers it fails on the clock instead of
    // on the rule it exists to pin.
    $process->setTimeout(600);
    $process->run();

    $output = $process->getOutput().$process->getErrorOutput();
    expect($output)->not->toContain('Cross-module Internal/Models import forbidden');
});

it('passes against empty module skeletons at level max', function (): void {
    $process = new Process([
        PHP_BINARY,
        '-d', 'memory_limit=2G',
        base_path('vendor/bin/phpstan'),
        'analyse',
        'Modules',
        '--no-progress',
        '--error-format=raw',
        '--no-ansi',
        '--memory-limit=1G',
    ], base_path());
    // Symfony's 60-second default assumes this analysis has the machine to
    // itself; under pcov and parallel workers it fails on the clock instead of
    // on the rule it exists to pin.
    $process->setTimeout(600);
    $process->run();

    expect($process->getExitCode())->toBe(0);
});
