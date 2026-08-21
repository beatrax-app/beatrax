<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Exceptions;

use RuntimeException;

// A raw .eml blob could not be written. put() tears down the temp file before
// this propagates, so the tree is never left half-written.
final class EmlBlobWriteException extends RuntimeException {}
