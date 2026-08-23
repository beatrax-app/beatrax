<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

// The shape of one row in transaction_search_docs: counterparty, description
// and tax note joined by a byte no transaction text carries, so FTS5 cannot
// match across two fields as if they were one phrase. The writer, the
// reindexer and the reader all have to agree on it, and the reader has to put
// something legible in its place — a snippet() window spans the join, and the
// raw byte drew a missing-character box on the phone.
final class SearchDocumentBody
{
    public const FIELD_SEPARATOR = "\x0C";

    public const DISPLAY_SEPARATOR = ' · ';

    public static function join(string $counterparty, string $description, string $note): string
    {
        return $counterparty.self::FIELD_SEPARATOR.$description.self::FIELD_SEPARATOR.$note;
    }

    // A row usually carries no tax note, so its body ends on a join with
    // nothing after it, and a row with no description has two in a row.
    // Neither separates anything the reader can see.
    public static function toDisplay(string $snippetBody): string
    {
        $mark = preg_quote(trim(self::DISPLAY_SEPARATOR), '/');
        $joined = str_replace(self::FIELD_SEPARATOR, self::DISPLAY_SEPARATOR, $snippetBody);
        $collapsed = preg_replace('/(?:\s*'.$mark.'\s*)+/u', self::DISPLAY_SEPARATOR, $joined) ?? $joined;

        return preg_replace('/^(?:\s*'.$mark.'\s*)|(?:\s*'.$mark.'\s*)$/u', '', $collapsed) ?? $collapsed;
    }
}
