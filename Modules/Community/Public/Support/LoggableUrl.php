<?php

declare(strict_types=1);

namespace Modules\Community\Public\Support;

// A URL on its way into a log line. The query string of a suggest-mapping URL
// carries the YAML body, i.e. the user's own statement description, and
// encryption at rest exists to keep that off the disk. The retained path still
// holds a stable sha256 prefix of the description, which is the branch name.
//
// Both shells log the same URL, so both strip it the same way: the fallback
// shell used to log the whole thing, including the query, into the file the
// dev-mode log viewer renders.
final class LoggableUrl
{
    public static function withoutQuery(string $url): string
    {
        return substr($url, 0, strcspn($url, '?#'));
    }
}
