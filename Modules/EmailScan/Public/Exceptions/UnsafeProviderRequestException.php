<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Exceptions;

use RuntimeException;

// A request refused before it was sent, guarding a bearer token against going
// somewhere it does not belong. RuntimeException rather than a logic one:
// most of these inspect a provider-returned nextLink, not an argument.
final class UnsafeProviderRequestException extends RuntimeException {}
