<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Exceptions;

use RuntimeException;

// Raised inside the commit transaction so the progress row rolls back with the
// import that never happened. A refusal per run is survivable and logged as
// one; every run refused imports no transaction at all, and the step reported
// that as a finished import and advanced the wizard past it.
final class EveryStagedRunWasRefusedException extends RuntimeException
{
    public function __construct(public readonly int $runsOffered)
    {
        parent::__construct(sprintf(
            'All %d staged import runs were refused at commit, so nothing was imported.',
            $runsOffered,
        ));
    }
}
