<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Exceptions;

use Modules\Core\Public\Support\MessageNamesNoUserData;
use RuntimeException;

// The message names the shape of an ICS statement and nothing read out of one,
// so the preview may show it as what the parser reported.
final class IcsStatementDateUnreadableException extends RuntimeException implements MessageNamesNoUserData
{
    public function __construct()
    {
        parent::__construct(
            'This statement states no date that can be read, and a row on an ICS statement carries a day and a month but never a year. Every transaction in the file takes its year from the statement date, so none of them can be dated. Download the statement again from Mijn ICS.'
        );
    }
}
