<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

// phpstan.neon excludes tools/PhpStan/Rules/Fixtures/*, so the CI job never
// analyses these and nothing else proves FingerprintTupleRule still fires.
// `-d memory_limit=2G` on the child for the reason PhpStanBoundaryRuleTest
// gives: the runtime bounds the heap before PHPStan parses its own flag.
function fingerprintTupleFixtureOutput(string $fixture): string
{
    $process = new Process([
        PHP_BINARY,
        '-d', 'memory_limit=2G',
        base_path('vendor/bin/phpstan'),
        'analyse',
        '--configuration='.base_path('phpstan-fixtures.neon'),
        base_path('tools/PhpStan/Rules/Fixtures/'.$fixture),
        '--no-progress',
        '--error-format=raw',
        '--no-ansi',
        '--memory-limit=1G',
    ], base_path());
    $process->setTimeout(600);
    $process->run();

    return $process->getOutput().$process->getErrorOutput();
}

it('names the column a bad update moves, including one reached through a value object', function (): void {
    $output = fingerprintTupleFixtureOutput('BadFingerprintTupleFixture.php');

    // The amount arrives as TransactionAmount::relate(...)->toColumns(), so no
    // column name appears at the call site — this is the spelling that caused
    // the defect, and reading it needs the inferred array shape, not the source.
    expect($output)->toContain('This update moves amount_minor, currency on transactions without writing fingerprint');
    expect($output)->toContain('This update moves counterparty_normalized on transactions without writing fingerprint');
});

it('stays quiet when the digest is written in the same array, and when no tuple column moves', function (): void {
    expect(fingerprintTupleFixtureOutput('GoodFingerprintTupleFixture.php'))
        ->not->toContain('without writing fingerprint');
});
