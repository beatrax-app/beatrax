<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers;

use Modules\Migration\Public\Enums\MigrationSourceProduct;

final class Ynab4Parser extends AbstractYnabParser
{
    public function format(): string
    {
        return MigrationSourceProduct::Ynab4->value;
    }
}
