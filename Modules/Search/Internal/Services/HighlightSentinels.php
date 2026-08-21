<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

// The control-char markers SQLite's highlight()/snippet() wrap matches in
// (never present in transaction text): FtsCandidateResolver emits them in
// the highlight SQL and SearchRowMapper swaps them for XSS-safe <mark>
// tags, so both sides share one source of truth for the sentinel pair.
final class HighlightSentinels
{
    public const START = "\x02";

    public const END = "\x03";
}
