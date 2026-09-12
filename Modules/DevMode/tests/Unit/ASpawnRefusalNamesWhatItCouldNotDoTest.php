<?php

declare(strict_types=1);

use Modules\DevMode\Internal\Exceptions\SpawnProcessException;

// A spawn failure is read from a log after the fact, so the message is the
// only evidence of what the wrapper actually returned.
it('carries the stderr the bash wrapper produced', function (): void {
    expect(SpawnProcessException::bashWrapperFailed('bash: line 1: artisan: not found')->getMessage())
        ->toContain('bash: line 1: artisan: not found');
});

it('shows what it got instead of a pid', function (): void {
    expect(SpawnProcessException::pidUncapturable("not-a-pid\n")->getMessage())
        ->toContain('not-a-pid');
});

it('names the runs directory it could not create', function (): void {
    expect(SpawnProcessException::runsDirectoryUnwritable('/nonexistent/runs')->getMessage())
        ->toContain('/nonexistent/runs');
});
