<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use Modules\Core\Public\Support\MessageNamesNoUserData;
use Modules\Ingestion\Public\Contracts\NamesAFormatMismatch;
use RuntimeException;

final class UnsupportedFormatException extends RuntimeException implements MessageNamesNoUserData, NamesAFormatMismatch {}
