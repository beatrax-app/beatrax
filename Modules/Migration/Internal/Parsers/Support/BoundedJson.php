<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use JsonException;

// Decoding a blob that came out of somebody else's export file. The bounds are
// what makes it safe to read at all, so they belong beside the decode rather
// than at each caller: the goal-def interpreter and the rule-condition
// summariser each carried their own untrusting pair.
final class BoundedJson
{
    private const int MAX_BYTES = 65536;

    private const int MAX_DEPTH = 20;

    // Returns null rather than throwing: every caller is reading a field it
    // can do without, and a migration that aborts on one unreadable blob
    // imports nothing at all.
    public static function decode(string $json): mixed
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            return null;
        }

        try {
            return json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
