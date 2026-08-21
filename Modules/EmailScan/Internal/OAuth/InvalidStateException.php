<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;

// Mapped to HTTP 400 — the CSRF defence for /oauth/callback/{provider}.
final class InvalidStateException extends RuntimeException {}
