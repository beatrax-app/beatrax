<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('emits a BoundaryRule error on the bad fixture', function (): void {
    $process = new Process([
        base_path('vendor/bin/phpstan'),
        'analyse',
        '--configuration='.base_path('phpstan-fixtures.neon'),
        base_path('app/PhpStan/Rules/Fixtures/BadBoundaryFixture.php'),
        '--no-progress',
        '--error-format=raw',
        '--no-ansi',
    ], base_path());
    $process->run();

    $output = $process->getOutput().$process->getErrorOutput();
    expect($output)->toContain('Cross-module Internal/Models import forbidden');
});

it('emits zero errors on the good fixture', function (): void {
    $process = new Process([
        base_path('vendor/bin/phpstan'),
        'analyse',
        '--configuration='.base_path('phpstan-fixtures.neon'),
        base_path('app/PhpStan/Rules/Fixtures/GoodBoundaryFixture.php'),
        '--no-progress',
        '--error-format=raw',
        '--no-ansi',
    ], base_path());
    $process->run();

    $output = $process->getOutput().$process->getErrorOutput();
    expect($output)->not->toContain('Cross-module Internal/Models import forbidden');
});

it('passes against empty module skeletons at level max', function (): void {
    $process = new Process([
        base_path('vendor/bin/phpstan'),
        'analyse',
        'Modules',
        '--no-progress',
        '--error-format=raw',
        '--no-ansi',
    ], base_path());
    $process->run();

    expect($process->getExitCode())->toBe(0);
});
