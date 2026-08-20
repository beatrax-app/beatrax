<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Exceptions;

use RuntimeException;

// A raw .eml blob could not be written to disk: the temp file would not open,
// the write was short, or the chmod or atomic rename failed. put() tears down
// the temp file before this propagates, so the on-disk tree is never left
// half-written — the caller's rollback deletes the canonical path regardless.
final class EmlBlobWriteException extends RuntimeException {}
