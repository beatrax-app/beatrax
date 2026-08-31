<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Enums;

// Why a paginated fetch stopped walking. Everything but Exhausted means the
// bank had more to give and the connection has to say so: a silent truncation
// reads exactly like a quiet week.
enum FetchStop: string
{
    case Exhausted = 'exhausted';

    case PageCap = 'page_cap';

    case RowCap = 'row_cap';

    case RepeatedCursor = 'repeated_cursor';
}
