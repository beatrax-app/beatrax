<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Exceptions;

use InvalidArgumentException;

// `pots.currency` is frozen at creation and `accounts.default_currency` is not,
// so one account holds pots in two denominations and the cross-account guard
// never sees them. Moving between two of those is a conversion, and allocation
// books no rate.
final class CrossCurrencyTransferException extends InvalidArgumentException {}
