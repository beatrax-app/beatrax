<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Enums;

// The source_product column and the parser contracts stay string; this enum is
// the one canonical spelling callers map through.
enum MigrationSourceProduct: string
{
    case Ynab4 = 'ynab4';

    case Nynab = 'nynab';

    case Actual = 'actual';

    // Product names, not copy: each is spelled the same in every language, so
    // they are not translated and the select renders them from here.
    public function label(): string
    {
        return match ($this) {
            self::Ynab4 => 'YNAB4',
            self::Nynab => 'New YNAB (nYNAB)',
            self::Actual => 'Actual Budget',
        };
    }
}
