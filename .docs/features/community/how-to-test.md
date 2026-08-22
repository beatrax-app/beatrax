# `Community` — how to test

Practical recipes for exercising the `Community` module in isolation.

## Unit tests

- **Location:** `Modules/Community/tests/Unit/`
- **What they test:** the URL gates in `OpenExternalUrlAction`
  (`OpenExternalUrlActionTest`) — every rejected scheme + every
  rejected host, plus a happy-path `github.com/...` URL. Also the
  matcher: `CompiledNeedleDecidesTheSameTest` answers a fixture of
  awkward needles (accented and non-Latin, regex metacharacters, edges,
  punctuation-only, invalid UTF-8) needle-by-needle *and* through the
  precompiled path and fails on any disagreement, and
  `RegexPatternIsJudgedOnceTest` counts the warnings a bad corpus regex
  earns across a scan.
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
  - That a corpus scan resolves every description exactly as a
    row-by-row `containsToken()` walk over the same rows would
    (`CorpusScanAnswersTheSameTest`) — the guard on the precompiled
    scan, since a matcher that is faster but decides differently
    re-attributes bank lines silently.
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

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Community` module.

## Behavioral contracts

- **`OpenExternalUrlAction` only opens `https://github.com/…` URLs.**
  Two-gate validation (HTTPS scheme + host allow-list); any other URL
  raises `InvalidArgumentException` before the shell contract is
  called. (`tests/Unit/OpenExternalUrlActionTest.php`)
- **A non-HTTPS URL is rejected outright.** `http://`, `javascript:`,
  `file://`, and any other scheme `filter_var(FILTER_VALIDATE_URL)`
  accepts are rejected by the explicit `str_starts_with('https://')`
  check. (`tests/Unit/OpenExternalUrlActionTest.php`)
- **The seed runs on every `UserInstalled` dispatch without producing
  duplicate rows.** `SeedCommunityCorpus` upserts via `updateOrInsert`
  keyed on `(pattern, user_id IS NULL)`; re-dispatches are no-ops at
  the row level. (`tests/Feature/SeedCommunityCorpusTest.php`)
- **A malformed YAML file or a malformed entry is logged and
  skipped — never thrown.** Per-entry failure does not abort the
  loader; per-file failure does not abort the seed.
  (`tests/Feature/SeedCommunityCorpusTest.php`,
  `tests/Feature/CorpusUnknownCategoryWarningTest.php`)
- **A corpus entry naming an unknown category logs a `warning`
  diagnostic.** The seed still upserts the row (with the category
  cleared) so the corpus stays usable while the category mismatch is
  visible in the dev console. (`tests/Feature/CorpusUnknownCategoryWarningTest.php`)
- **The corpus migration runs cleanly on a fresh database.**
  (`tests/Feature/CommunityCorpusMigrationTest.php`)
- **A per-user override beats the global corpus row that matches the
  same description.** The precedence does not live inside a corpus
  method: `Import`'s `MerchantNameResolver::resolve` consults the
  reader's own `merchant_aliases` — exact first, then
  longest-needle-first generalized — before it asks the corpus at all.
  `CommunityCorpusQuery` never sees a per-user row to prefer: every one
  of its lookups filters `user_id IS NULL`, and `SeedCommunityCorpus`
  is the only writer, so the table holds global rows only.
  (`Modules/Import/tests/Feature/MerchantNameResolverCommunityTest.php`)
- **The suggest modal does not open the browser without an explicit
  click.** `SuggestMappingModal::submit` is the only entry point that
  calls `OpenExternalUrlAction`; the modal mounts in a closed state
  and shows the URL preview before submit.
  (`tests/Feature/SuggestMappingModalSubmitTest.php`)
- **The GitHub Compare URL's branch slug is deterministic per
  pattern.** Two suggest calls for the same pattern produce the same
  branch (16-char prefix of `sha256($pattern)`), so iterating on one
  suggestion never spams the upstream branch list.
  (`tests/Feature/SuggestMappingModalSubmitTest.php`)
- **The YAML body in the Compare URL is double-quote-escaped per YAML
  1.2.** A name containing `"`, `\`, or a stray newline round-trips
  cleanly through GitHub's PR composer.
- **The `/triage` "Help others" CTA is only rendered when the user
  has opted into the shared list.** A user who turned the toggle off
  in `/settings` sees no community surface in their triage flow.
  (`tests/Feature/TriageHelpOthersCtaTest.php`)
- **The settings panel applies the toggle change atomically.**
  Switching the share-corpus toggle off persists in one write; the
  triage CTA disappears on the next render.
  (`tests/Feature/SharedListSettingsPanelTest.php`)
- **`MysteryMerchantSubmitted` fires exactly once per successful
  submit.** (`tests/Feature/SuggestMappingModalSubmitTest.php`)

## Edge cases

- **No corpus YAML files on disk** (e.g. a stripped-down test
  harness) — `CorpusLoader` logs a `warning` and returns an empty
  list; the listener no-ops. The app boots without a corpus.
- **Bundle running outside the NativePHP runtime** (local dev mode, CI
  tests) — the `Native\Desktop\Contracts\Shell` binding falls back
  to `NoOpShell`. `OpenExternalUrlAction` calls succeed, log the
  would-be URL, and do not launch a browser. Tests bind a `ShellFake`
  via the container to assert intent without a side-effect.
- **A re-run of `php artisan beatrax:install`** — `UserInstalled`
  fires again; the seed listener upserts; nothing duplicates.
- **A constraint violation on a single corpus entry** — the per-entry
  try/catch in `SeedCommunityCorpus` logs at `warning` and the loop
  moves on; the rest of the corpus still seeds.
- **A user submitting a suggestion they later edit** — the deterministic
  branch slug means the same Compare URL opens; the GitHub UI shows
  the updated body. No second branch is spawned.
- **A user toggling the share-corpus opt-in mid-session** — the
  triage page reflects the change on next render; the corpus
  reads do not change (the corpus is always consultable; the
  share toggle only governs outbound suggestions).
- **A corpus row whose `generalized_pattern` collides with another
  row's `pattern`** — the exact match wins, because
  `MerchantNameResolver::resolve` chains the three corpus lookups with
  `??` in that order: `CommunityCorpusQuery::lookupExact`, then
  `lookupGeneralized`, then `lookupRegex`. Each is a separate query;
  no single method evaluates both tiers. Within the generalized tier
  the rows are scanned longest-needle-first, so the first hit is also
  the most specific one.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `Clock`, `UserInstalled` event.
  - [`Import`](../import/how-to-test.md) — `PatternGeneralizer` (computes
    the `generalized_pattern` column the corpus stores).
  - `Native\Desktop\Contracts\Shell` from the NativePHP package; the
    actual binding is owned by [`Desktop`](../desktop/how-to-test.md), with
    `Internal\Shell\NoOpShell` as the in-module fallback when
    `Desktop` has not bound the contract.
- **Depended on by**
  - [`Import`](../import/how-to-test.md) — the import preview consults
    `CommunityCorpusQuery` per row to suggest a friendly name for an
    unknown merchant.
  - [`Categorization`](../categorization/how-to-test.md) — the triage row
    surfaces the "Help others identify this" CTA, gated on the
    `offerToContribute` toggle this module owns.

## Configuration + feature flags

- `config('community.github_compare_base')` — the base URL the
  Compare-URL builder uses. Default
  `https://github.com/beatrax-app/beatrax/compare/main`, overridable
  via the `BEATRAX_GITHUB_COMPARE_BASE` env var without a code
  change. This is the single configuration knob the public-release
  boundary needs to flip the repo destination at publication.
- `users.community_settings` (per-user JSON column) — the opt-in
  toggles `SharedListSettingsPanel` reads + writes:
  `consult_corpus`, `share_suggestions`. Default `false` on both for
  every new user.
- No environment flag changes the privacy posture: the HTTPS scheme
  - `github.com` allow-list in `OpenExternalUrlAction` are hard-
  coded, not config-driven.
