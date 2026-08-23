<?php

declare(strict_types=1);

use Modules\Core\Public\Support\ProjectLinks;

it('derives every project link from the one origin', function (): void {
    expect(ProjectLinks::ISSUES_URL)->toBe(ProjectLinks::REPO_URL.'/issues')
        ->and(ProjectLinks::NEW_ISSUE_URL)->toBe(ProjectLinks::REPO_URL.'/issues/new')
        ->and(ProjectLinks::LATEST_RELEASE_URL)->toBe(ProjectLinks::REPO_URL.'/releases/latest')
        ->and(ProjectLinks::COMPARE_BASE_URL)->toBe(ProjectLinks::REPO_URL.'/compare/main')
        ->and(ProjectLinks::releaseTagUrl('v1.4.0'))->toBe(ProjectLinks::REPO_URL.'/releases/tag/v1.4.0');
});

it('escapes a release tag instead of interpolating it into the path', function (): void {
    expect(ProjectLinks::releaseTagUrl('release/1.4.0'))
        ->toBe(ProjectLinks::REPO_URL.'/releases/tag/release%2F1.4.0')
        ->and(ProjectLinks::releaseTagUrl('../../beatrax-app/other'))
        ->toBe(ProjectLinks::REPO_URL.'/releases/tag/..%2F..%2Fbeatrax-app%2Fother');
});
