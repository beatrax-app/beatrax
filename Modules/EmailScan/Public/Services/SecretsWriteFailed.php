<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use RuntimeException;

// The message carries the path and nothing else: the JSON payload it failed to
// write is credential material and must not reach the exception log.
final class SecretsWriteFailed extends RuntimeException {}
