<?php

declare(strict_types=1);

return [

    // The literal `.../compare/{baseRef}` prefix only —
    // GitHubCompareUrlBuilder appends `...{branch}?expand=1&body=...`.
    'github_compare_base' => env(
        'BEATRAX_GITHUB_COMPARE_BASE',
        'https://github.com/beatrax-app/beatrax/compare/main',
    ),

    'github_issues_url' => env(
        'BEATRAX_GITHUB_ISSUES_URL',
        'https://github.com/beatrax-app/beatrax/issues',
    ),

    // Laid out as <type>/<country>.yaml — merchants, government, bank-fees —
    // and a file's country is inferred from its filename (de.yaml -> DE).
    'corpus' => [
        'root' => 'resources/corpus',
    ],

    // Null means resolve `corpus.root` against the injected
    // `Application::basePath()`, so relocating the tree needs no code change.
    // Tests bind a fixture directory here instead of touching the shipped corpus.
    'app_root' => null,

    // Seeded into `users.community_settings` until the user saves a choice.
    // `update_on_app_updates` ships disabled: the live-update mechanism it
    // would drive does not exist yet.
    'defaults' => [
        'use_shared_list' => true,
        'offer_to_contribute' => true,
        'update_on_app_updates' => false,
    ],

];
