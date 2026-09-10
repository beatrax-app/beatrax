<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

// One row of transaction_search_docs: counterparty, description, the reader's
// own notes and the tax note, joined by a byte no transaction text carries so
// FTS5 cannot match across two fields as one phrase. Writer, reindexer and
// reader must agree on it; the reader must replace it, or it draws a tofu box.
final class SearchDocumentBody
{
    // The index tokenizer is trigram, so an FTS5 token is a three-character
    // window: a word shorter than this cannot be a MATCH predicate at all, and
    // every reader of the index measures against the same width.
    public const int TRIGRAM_WIDTH = 3;

    public const string FIELD_SEPARATOR = "\x0C";

    public const string DISPLAY_SEPARATOR = ' · ';

    // Variadic because a split transaction contributes one field per leg, so
    // the field count is the row's, not the schema's. Every caller passes the
    // same fields in the same order: a body composed two ways is one a rebuild
    // silently rewrites.
    public static function join(string ...$fields): string
    {
        return implode(self::FIELD_SEPARATOR, $fields);
    }

    // A row usually carries neither note, so its body ends on joins with
    // nothing after them, and a row with no description has two in a row.
    // Neither separates anything the reader can see.
    public static function toDisplay(string $snippetBody): string
    {
        $mark = preg_quote(trim(self::DISPLAY_SEPARATOR), '/');
        $joined = str_replace(self::FIELD_SEPARATOR, self::DISPLAY_SEPARATOR, $snippetBody);
        $collapsed = preg_replace('/(?:\s*'.$mark.'\s*)+/u', self::DISPLAY_SEPARATOR, $joined) ?? $joined;

        return preg_replace('/^(?:\s*'.$mark.'\s*)|(?:\s*'.$mark.'\s*)$/u', '', $collapsed) ?? $collapsed;
    }
}
