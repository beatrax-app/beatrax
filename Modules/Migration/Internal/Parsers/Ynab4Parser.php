<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers;

/**
 * `ParsesMigrationSource` implementation for classic YNAB4 desktop exports
 * (loose two-CSV folder or ZIP, `Register.csv` + `Budget.csv` with the
 * separate `Master Category`/`Sub Category` columns). All parsing logic
 * lives in `AbstractYnabParser` — this class supplies only the format
 * identifier.
 */
final class Ynab4Parser extends AbstractYnabParser
{
    public function format(): string
    {
        return 'ynab4';
    }
}
