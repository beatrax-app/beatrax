<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Exceptions;

use RuntimeException;

// A request refused before it was sent — a message id failing the allow-list,
// a non-HTTPS scheme, a host outside the provider's own — each guarding a
// bearer token against going somewhere it does not belong. Extends
// RuntimeException: most inspect a provider-returned nextLink, not an argument.
final class UnsafeProviderRequestException extends RuntimeException {}
