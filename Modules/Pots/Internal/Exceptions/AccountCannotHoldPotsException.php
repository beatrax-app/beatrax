<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Exceptions;

use InvalidArgumentException;

// A pot carves up money the reader HOLDS. On a liability account the balance is
// what is owed, so every pot on one is over-allocated by construction and can
// never be funded. The picker never offered such an account; the id did.
final class AccountCannotHoldPotsException extends InvalidArgumentException {}
