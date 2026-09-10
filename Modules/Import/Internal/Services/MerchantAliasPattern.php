<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Modules\Import\Internal\Exceptions\MerchantAliasPatternTooShortException;
use Modules\Import\Public\Services\AliasMatchPreviewQuery;

// The floor on a generalized pattern is the value's rule, not the rule of
// whichever screen happens to be open: the pattern matches as a whole token
// against every description the reader owns, so two characters rename a ledger.
// Every writer of the column asks here, and the arch guard reads that it did.
final class MerchantAliasPattern
{
    public static function isBelowFloor(string $pattern): bool
    {
        return mb_strlen(trim($pattern)) < AliasMatchPreviewQuery::MIN_PATTERN_LENGTH;
    }

    public static function orRefuse(string $pattern): string
    {
        if (self::isBelowFloor($pattern)) {
            throw new MerchantAliasPatternTooShortException(AliasMatchPreviewQuery::MIN_PATTERN_LENGTH);
        }

        return trim($pattern);
    }
}
