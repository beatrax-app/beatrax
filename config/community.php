<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | GitHub Compare URL base
    |--------------------------------------------------------------------------
    |
    | The base URL the SuggestMappingModal uses to compose a draft-PR
    | Compare link in the system browser. The value is the literal
    | `https://github.com/{owner}/{repo}/compare/{baseRef}` prefix; the
    | GitHubCompareUrlBuilder appends `...{branch}?expand=1&body=...`.
    |
    | Set via `BEATRAX_GITHUB_COMPARE_BASE` in .env. The fallback points
    | at the canonical bundled repository so the modal launches a working
    | URL even when no environment override is in place.
    |
    */

    'github_compare_base' => env(
        'BEATRAX_GITHUB_COMPARE_BASE',
        'https://github.com/nightworksio/beatrax/compare/main',
    ),

    /*
    |--------------------------------------------------------------------------
    | Bundled corpus YAML asset paths
    |--------------------------------------------------------------------------
    |
    | Paths to the YAML files the CorpusLoader reads on UserInstalled.
    | Both paths are relative to the application root; tests bind a
    | temporary path here so the loader walks a fixture file without
    | mutating the shipped corpus on disk. Setting either path to a
    | non-existent file causes the loader to log a warning and skip the
    | file — the other path still loads normally.
    |
    */

    'corpus' => [
        'bundled_path' => 'resources/corpus/merchant-mappings.yaml',
        'heuristics_path' => 'resources/corpus/built-in-heuristics.yaml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Settings → Shared merchant list toggles
    |--------------------------------------------------------------------------
    |
    | Default values applied to `users.community_settings` before the
    | user opens the Settings panel and saves an explicit choice. The
    | resolver and the HelpOthersTriageButton both fall back to these
    | values when the JSON column is null or missing a key. Toggle 3
    | (`updateOnAppUpdates`) ships disabled until the live-update
    | mechanism lands; the inline note in the settings panel is
    | version-agnostic.
    |
    */

    'defaults' => [
        'use_shared_list' => true,
        'offer_to_contribute' => true,
        'update_on_app_updates' => false,
    ],

];
