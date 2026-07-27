# `Community` — specs

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
- **A per-user override beats the global corpus row at the same
  pattern.** `CommunityCorpusQuery::findFor` returns the user's row
  first, falling back to `user_id IS NULL`.
  (`tests/Feature/MysteryMerchantsPageTest.php`)
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
  row's `pattern`** — `CommunityCorpusQuery::findFor` evaluates the
  exact-pattern match first, the generalized-pattern fuzzy match
  second; the exact match wins.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/specs.md) — `Clock`, `UserInstalled` event.
  - [`Import`](../import/specs.md) — `PatternGeneralizer` (computes
    the `generalized_pattern` column the corpus stores).
  - `Native\Desktop\Contracts\Shell` from the NativePHP package; the
    actual binding is owned by [`Desktop`](../desktop/specs.md), with
    `Internal\Shell\NoOpShell` as the in-module fallback when
    `Desktop` has not bound the contract.
- **Depended on by**
  - [`Import`](../import/specs.md) — the import preview consults
    `CommunityCorpusQuery` per row to suggest a friendly name for an
    unknown merchant.
  - [`Categorization`](../categorization/specs.md) — the triage row
    surfaces the `HelpOthersTriageButton` Livewire component when a
    mystery merchant appears in the triage queue.

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
  + `github.com` allow-list in `OpenExternalUrlAction` are hard-
  coded, not config-driven.
