# `Community` — how to test

Practical recipes for exercising the `Community` module in isolation.

## Unit tests

- **Location:** `Modules/Community/tests/Unit/`
- **What they test:** the URL gates in `OpenExternalUrlAction`
  (`OpenExternalUrlActionTest`) — every rejected scheme + every
  rejected host, plus a happy-path `github.com/...` URL.
- **Common stubs:** the action is constructed with a fake `Shell`
  (typically `Mockery::spy(Shell::class)`) and a `LoggerInterface`
  spy. No HTTP layer is involved.

## Feature tests

- **Location:** `Modules/Community/tests/Feature/`
- **What they test:**
  - The migration's clean apply against an in-memory SQLite
    (`CommunityCorpusMigrationTest`).
  - The seed listener's idempotence + tolerance to malformed
    YAML files (`SeedCommunityCorpusTest`).
  - The unknown-category warning path
    (`CorpusUnknownCategoryWarningTest`).
  - The `/community/mystery-merchants` Livewire page
    (`MysteryMerchantsPageTest`).
  - The settings panel's toggle persistence
    (`SharedListSettingsPanelTest`).
  - The end-to-end suggest submit
    (`SuggestMappingModalSubmitTest`).
  - The `/triage` "Help others" CTA visibility gate
    (`TriageHelpOthersCtaTest`).
- **Setup:** every test uses `RefreshDatabase`. Tests that exercise
  the `Shell` binding either bind a `Mockery::spy(Shell::class)` to
  the container or rely on the `NoOpShell` fallback registered by
  the provider when no other binding exists.

## Contract / arch invariants

- The repo-wide `noUnsanctionedShellOpenExternal` arch invariant —
  forbids any class outside `Modules\Community\Public\Actions\OpenExternalUrlAction`
  from calling `Shell::openExternal`. The action is the single
  sanctioned wrapper; routing every browser-launch through it keeps
  the host allow-list a one-line audit.
- The repo-wide `noNativePhpImportsOutsideDesktopModule` invariant —
  forbids `use Native\…\*` outside `Modules\Desktop\` and (by
  carve-out) the `Shell` contract that `OpenExternalUrlAction`
  consumes.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Community/tests

# Just the URL-gate unit test
vendor/bin/pest Modules/Community/tests/Unit/OpenExternalUrlActionTest.php

# Stop on first failure for a focused debug session
vendor/bin/pest Modules/Community/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **`OpenExternalUrlAction` throws `InvalidArgumentException: host
  not allow-listed`** — the URL host is something other than
  `github.com`. If a future phase legitimately needs another host
  (e.g. a Bluesky / Mastodon share button), extend
  `ALLOWED_HOSTS` and add a unit-test row covering every newly-
  permitted host. Do not call `Shell::openExternal` directly to
  bypass — the arch invariant will fail loud and the public-release
  audit relies on that single chokepoint.
- **The `/community/mystery-merchants` page shows zero rows on a
  fresh install** — confirm `UserInstalled` fired and that the
  YAML corpus files exist at `resources/corpus/*.yaml`. Read the
  Laravel log for the loader's `warning` lines; a missing file
  produces `corpus file not found at <path>`.
- **A new corpus entry doesn't appear after editing the YAML** —
  the seed only runs on `UserInstalled`. For local iteration, run
  `php artisan tinker` and dispatch the listener manually:
  `app(SeedCommunityCorpus::class)->handle(new UserInstalled($user->id))`.
- **The suggest modal opens the wrong repo on Compare** — the env
  var `BEATRAX_GITHUB_COMPARE_BASE` is set to a fork. Override in
  `.env` for local development; the production bundle ships with
  the upstream default.
- **`ShellFake` not picking up the call in a feature test** — the
  test must rebind the container BEFORE the Livewire component
  mounts. The provider's conditional `! $this->app->bound(...)` skip
  means a fake registered AFTER provider boot is honoured; one
  registered too early gets overwritten.
