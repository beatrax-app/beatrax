<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Exceptions;

use RuntimeException;

// Terminal: the whole fallback chain failed and no rate is available, so a
// caller catching this must take the passthrough path rather than converting.
final class AllProvidersFailed extends RuntimeException {}
