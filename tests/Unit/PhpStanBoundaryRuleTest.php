<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Spawning PHPStan via Symfony Process inherits the parent's
 * `memory_limit` ini setting on the child (PHP's default is 128M).
 * PHPStan's `--memory-limit=1G` CLI flag is parsed by PHPStan AFTER
 * the PHP runtime has already bounded the heap at 128M, so the
 * `Modules` walk OOMs on parallel CI runs before PHPStan can raise
 * the bound. Prepending `php -d memory_limit=2G` lifts the child's
 * heap budget at the runtime layer, eliminating the intermittent
 * OOM seen during Plan 17-08 baseline.
 */
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
    // Symfony's Process defaults to 60 seconds, which is an assumption about
    // an uninstrumented run: under pcov the coverage job takes longer than
    // that to analyse a single fixture, and the test failed on the clock
    // rather than on the rule it exists to pin.
    $process->setTimeout(300);
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
    // Symfony's Process defaults to 60 seconds, which is an assumption about
    // an uninstrumented run: under pcov the coverage job takes longer than
    // that to analyse a single fixture, and the test failed on the clock
    // rather than on the rule it exists to pin.
    $process->setTimeout(300);
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
    // Symfony's Process defaults to 60 seconds, which is an assumption about
    // an uninstrumented run: under pcov the coverage job takes longer than
    // that to analyse a single fixture, and the test failed on the clock
    // rather than on the rule it exists to pin.
    $process->setTimeout(300);
    $process->run();

    expect($process->getExitCode())->toBe(0);
});
