<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

enum CopyParamKind: string
{
    case Day = 'day';

    case Date = 'date';

    case DateWithYear = 'date_year';

    case DateAndTime = 'date_time';

    case Lang = 'lang';

    case Money = 'money';

    case Category = 'category';

    // The shape a stored value must still have to be rendered, for the kinds
    // that pack more than one field into it. Null means any non-empty value
    // decodes, which is every kind that stores one.
    public function storedValuePattern(): ?string
    {
        return match ($this) {
            // A 0-or-1 flag, the slug, then the stored name — the name last so
            // one holding a separator still decodes. A slug holds none.
            self::Category => '/^[01]\|[^|]*\|/',
            self::Money => '/^-?\d+\|[A-Za-z]{3}$/',
            self::Day, self::Date, self::DateWithYear, self::DateAndTime, self::Lang => null,
        };
    }
}
