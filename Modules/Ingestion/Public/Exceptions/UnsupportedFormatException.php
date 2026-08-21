<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use Modules\Core\Public\Support\MessageNamesNoUserData;
use RuntimeException;

final class UnsupportedFormatException extends RuntimeException implements MessageNamesNoUserData {}
