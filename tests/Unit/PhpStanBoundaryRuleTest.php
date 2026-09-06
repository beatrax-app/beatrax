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
    expect($output)->toContain('Cross-module import forbidden');
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
    expect($output)->not->toContain('Cross-module import forbidden');
});

// The third test in this file ran `phpstan analyse Modules` and asserted a zero
// exit. With no --configuration it picked up phpstan.neon, whose own paths are
// Modules, app and bootstrap/app.php at the same level with the same rules — so
// a CLI path argument only NARROWED the CI job's scope, and its error set was a
// subset of one the `static analysis (PHP 8.5)` job already fails on. It could
// not go red on its own, it did not test the empty module skeleton its name
// claimed, and it cost ~125s cold in whichever shard held the Unit suite.
//
// The two above are the opposite case and stay: phpstan.neon excludes
// app/PhpStan/Rules/Fixtures/*, so the CI job never analyses these fixtures and
// nothing else proves the custom BoundaryRule still fires.
