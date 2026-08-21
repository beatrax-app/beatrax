<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Examples;

// DELIBERATELY BAD: another module's Internal namespace. The rule fires on
// the use-statement alone, so the imported class need not exist.
use Modules\Ledger\Internal\Casts\MoneyMinorCast;

final class BadBoundaryFixture
{
    public function __construct(protected MoneyMinorCast $cast) {}
}
