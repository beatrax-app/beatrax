<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The project's GitHub origin, as one place a view can @use. Everything below
// hangs off REPO_URL so renaming the org or the repository is a single edit,
// and a Blade template reaches it as a class constant rather than a global
// helper, which mobile-app/'s second autoloader root would not resolve.
final class ProjectLinks
{
    public const string REPO_URL = 'https://github.com/beatrax-app/beatrax';

    public const string ISSUES_URL = self::REPO_URL.'/issues';

    public const string NEW_ISSUE_URL = self::ISSUES_URL.'/new';

    public const string LATEST_RELEASE_URL = self::REPO_URL.'/releases/latest';

    public const string COMPARE_BASE_URL = self::REPO_URL.'/compare/main';

    public const string CONTRIBUTING_URL = self::REPO_URL.'/blob/main/CONTRIBUTING.md';

    // The tag arrives from alert metadata rather than from a fixed list, so it
    // is encoded instead of interpolated: a `/` in a tag name would otherwise
    // re-point the href at a different path under the repository.
    public static function releaseTagUrl(string $tag): string
    {
        return self::REPO_URL.'/releases/tag/'.rawurlencode($tag);
    }
}
