<?php

declare(strict_types=1);

use Modules\Core\Public\Support\ProjectLinks;

return [

    // The literal `.../compare/{baseRef}` prefix only —
    // GitHubCompareUrlBuilder appends `...{branch}?expand=1&body=...`.
    'github_compare_base' => env(
        'BEATRAX_GITHUB_COMPARE_BASE',
        ProjectLinks::COMPARE_BASE_URL,
    ),

    'github_issues_url' => env(
        'BEATRAX_GITHUB_ISSUES_URL',
        ProjectLinks::ISSUES_URL,
    ),

    // Laid out as <type>/<country>.yaml — merchants, government, bank-fees —
    // and a file's country is inferred from its filename (de.yaml -> DE).
    'corpus' => [
        'root' => 'resources/corpus',
    ],

    // Null resolves `corpus.root` against the injected `Application::basePath()`.
    // Tests bind a fixture directory rather than touch the shipped corpus.
    'app_root' => null,

];
