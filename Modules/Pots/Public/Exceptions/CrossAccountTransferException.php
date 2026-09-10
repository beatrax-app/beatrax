<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Exceptions;

use InvalidArgumentException;

// Typed so the page can name the account the target sits in, which is the fact
// that explains the refusal — the reader was told to check fields that were all
// correct. Two pots on ONE account can still hold two currencies, which is
// CrossCurrencyTransferException's to refuse, not this one's.
final class CrossAccountTransferException extends InvalidArgumentException {}
