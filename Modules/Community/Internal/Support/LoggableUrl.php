<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Support;

// A suggest-mapping URL carries the YAML body in its query string -- the
// reader's own statement description, which encryption at rest exists to keep
// off the disk. Both shells log that URL, so both strip it here. The retained
// path still holds the sha256 prefix that is the branch name.
final class LoggableUrl
{
    public static function withoutQuery(string $url): string
    {
        return substr($url, 0, strcspn($url, '?#'));
    }
}
