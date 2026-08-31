<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Exceptions;

use InvalidArgumentException;

// Typed so the page can name the field and the language, rather than printing
// the developer-facing message the throw site carries.
final class PotLinkedToCategoryException extends InvalidArgumentException {}
