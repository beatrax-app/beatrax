<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Banking;

use Modules\Import\Internal\Parsers\DutchNarrativeHinter;
use Modules\Ingestion\Public\Enums\SourceFormat;

final class Mt940PaymentTypeHinter extends DutchNarrativeHinter
{
    protected const SOURCE_FORMAT = SourceFormat::Mt940->value;
}
