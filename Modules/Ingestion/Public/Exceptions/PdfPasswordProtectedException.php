<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use RuntimeException;

// The file declares its own encryption, so no reader on any platform opens it
// without the password. Public because it is the one PDF refusal the reader
// clears themselves, by saving an unprotected copy first.
final class PdfPasswordProtectedException extends RuntimeException {}
