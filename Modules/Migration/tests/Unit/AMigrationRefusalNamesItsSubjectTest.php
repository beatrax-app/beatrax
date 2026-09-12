<?php

declare(strict_types=1);

use Modules\Migration\Internal\Exceptions\ExtractionDirectoryException;
use Modules\Migration\Internal\Exceptions\UnresolvedStagedAccountException;

// Both refusals quote the identifier they could not resolve, which is what
// makes a failed import actionable rather than merely failed.
it('quotes the staged account it could not resolve', function (): void {
    expect((new UnresolvedStagedAccountException('acct-external-42'))->getMessage())
        ->toContain("'acct-external-42'");
});

it('quotes the extraction directory it could not create', function (): void {
    expect((new ExtractionDirectoryException('/tmp/migration-run-7'))->getMessage())
        ->toContain("'/tmp/migration-run-7'");
});
