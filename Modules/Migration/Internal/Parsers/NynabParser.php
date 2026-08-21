<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers;

use Modules\Migration\Internal\Enums\MigrationSourceProduct;

final class NynabParser extends AbstractYnabParser
{
    public function format(): string
    {
        return MigrationSourceProduct::Nynab->value;
    }
}
