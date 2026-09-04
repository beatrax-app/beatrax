<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The product name and the tail every titled page appends to it, as one place
// a view can @use. The name has already been restyled once; a copy written out
// at every render() is the copy the next restyle misses.
final class Brand
{
    public const string NAME = 'Beatrax';

    // A middle dot rather than a dash, which is what every titled page here
    // was already using before this constant existed.
    public const string TITLE_SUFFIX = ' · '.self::NAME;
}
