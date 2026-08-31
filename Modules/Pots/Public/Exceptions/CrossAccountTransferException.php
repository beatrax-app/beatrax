<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Exceptions;

use InvalidArgumentException;

// Every cross-currency move is one of these, and the reader was told to check
// fields that were all correct. Typed so the page can name the account the
// target sits in, which is the fact that explains the refusal.
final class CrossAccountTransferException extends InvalidArgumentException {}
