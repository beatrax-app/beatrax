<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers;

use Modules\Migration\Public\Enums\MigrationSourceProduct;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class NynabParser extends AbstractYnabParser
{
    public function format(): string
    {
        return MigrationSourceProduct::Nynab->value;
    }
}
