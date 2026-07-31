<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Examples;

// DELIBERATELY BAD: importing another module's Internal namespace.
// The BoundaryRule rejects this on the use-statement alone — the imported
// class does not need to exist for the rule to fire.
use Modules\Ledger\Internal\Casts\MoneyMinorCast;

final class BadBoundaryFixture
{
    public function __construct(protected MoneyMinorCast $cast) {}
}
