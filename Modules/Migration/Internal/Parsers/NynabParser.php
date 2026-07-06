<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers;

/**
 * `ParsesMigrationSource` implementation for nYNAB ("Plan", the current web
 * app) exports — the identical two-CSV `Register.csv` + `Budget.csv` shape
 * as YNAB4, with the single combined `Category Group/Category` column
 * (RESEARCH.md's one confirmed divergence). All parsing logic lives in
 * `AbstractYnabParser`; this class supplies only the format identifier.
 */
final class NynabParser extends AbstractYnabParser
{
    public function format(): string
    {
        return 'nynab';
    }
}
