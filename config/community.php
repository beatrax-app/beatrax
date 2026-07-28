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
        'https://github.com/beatrax-app/beatrax/compare/main',
    ),

    /*
    |--------------------------------------------------------------------------
    | GitHub Issues URL
    |--------------------------------------------------------------------------
    |
    | The URL the setup-wizard footer's "Need help?" link launches in the
    | system browser via `OpenExternalUrlAction`. Set via
    | `BEATRAX_GITHUB_ISSUES_URL` in .env. The fallback points at the
    | canonical bundled repository's issues page so a fresh install
    | launches a working URL with no env override.
    |
    */

    'github_issues_url' => env(
        'BEATRAX_GITHUB_ISSUES_URL',
        'https://github.com/beatrax-app/beatrax/issues',
    ),

    /*
    |--------------------------------------------------------------------------
    | Bundled corpus root
    |--------------------------------------------------------------------------
    |
    | Root directory (relative to the application root) holding the bundled
    | classification corpus, organised as <type>/<country>.yaml:
    |   merchants/<country>.yaml  → crowd-sourced merchant corpus (DB-seeded)
    |   government/<country>.yaml → government-agency resolver rules
    |   bank-fees/<country>.yaml  → bank-fee resolver rules
    |
    | A file's country code is inferred from its filename (de.yaml → DE).
    | Tests bind `community.app_root` (or this `root`) to a temporary path so
    | the loader walks fixture files without mutating the shipped corpus.
    |
    */

    'corpus' => [
        'root' => 'resources/corpus',
    ],

    /*
    |--------------------------------------------------------------------------
    | Application-root override (test-only)
    |--------------------------------------------------------------------------
    |
    | The CorpusLoader and ClassificationRuleProvider resolve the corpus
    | `root` above against this app root. When `null` (the production
    | default) they use the injected `Application::basePath()` so a
    | relocation of the source tree continues to work without a code
    | change. Feature tests bind a fixture directory here (or set `root`
    | directly) so the loader walks a temporary corpus tree without
    | mutating the shipped corpus.
    |
    */

    'app_root' => null,

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
