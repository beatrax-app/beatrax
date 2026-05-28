# `Community` — architecture

The `Community` module ships and consumes the crowd-sourced
merchant-identification corpus: a bundled YAML dataset that maps raw
bank-statement description fragments ("BCA*BOLDKING-37261") to
human-friendly merchant names ("Boldking shaving subscription"). It
hosts the `/community/mystery-merchants` triage page where the user can
help identify still-unknown patterns, the suggest-mapping modal that
composes a GitHub-Compare URL, and the per-user opt-in toggles for
whether to consult the corpus and whether to broadcast suggestions.

## What this module is for

A single bank-statement line like `IDEAL BCA*BOLDKING-37261` is
meaningless until a human knows what it is. The brand owners do not
self-identify on their statement strings, so every diederik user faces
the same long-tail of unfamiliar charges. The community corpus closes
that loop once per merchant: one user identifies "Boldking shaving
subscription" and that mapping ships to every other user on the next
release of the YAML file.

The privacy posture is strict and deliberate: every outbound surface is
opt-in, every outbound payload is the user's review, never their data,
and the only allow-listed external host is `github.com`. The user
publishes a suggestion by clicking a button that opens a pre-filled
GitHub Compare URL in their system browser; nothing leaves the device
without that explicit click.

What the module explicitly does NOT do:

- It never auto-applies a community mapping to a user's transactions.
  The corpus is offered as a suggestion in the import preview and the
  triage row; the user accepts it explicitly.
- It never sends telemetry. There is no "how often was this corpus row
  consulted" stream, no analytics, no background fetch.
- It never opens a URL that is not `https://github.com/…`. The
  `OpenExternalUrlAction` has a two-gate defence-in-depth check
  (HTTPS scheme + host allow-list) before reaching the shell contract.
- It never reaches a network on its own. The corpus is bundled inside
  the app (`resources/corpus/*.yaml`), seeded into a local table at
  first install, and updated only when the user downloads a new
  release.

## Module boundary

`Public/` exports the action that opens external URLs, the DTOs used in
events + queries, the event other modules can react to, and the read-
side query used by the import preview:

- **Actions/**
  - `OpenExternalUrlAction` — single sanctioned wrapper around the
    NativePHP `Shell::openExternal` contract. Validates HTTPS scheme +
    `github.com` host allow-list.
- **DTOs/**
  - `CorpusEntryDto` — one corpus row in flight (pattern, name,
    category, region, contributor, generalized_pattern).
  - `SuggestMappingDto` — payload the SuggestMappingModal feeds to
    `GitHubCompareUrlBuilder`.
- **Events/**
  - `MysteryMerchantSubmitted` — raised when the user submits a
    suggestion via the modal. Carries the DTO and the user id. No
    listener subscribes today; the event exists so a future
    aggregation module can observe contribution volume without
    coupling.
- **Services/**
  - `CommunityCorpusQuery` — the read-only surface other modules
    consume (import preview, triage row). Returns matches for a given
    pattern; never writes.

`Internal/` houses the seed pipeline, the Livewire pages, and the
NativePHP shell fallback:

- **Internal/Corpus/CorpusLoader** — reads the bundled YAML files
  (`merchant-mappings.yaml` + `built-in-heuristics.yaml`), validates
  each entry's required fields, computes a `generalized_pattern` via
  the `PatternGeneralizer` from `Import`, returns a stream of
  `CorpusEntryDto`. Per-entry failure tolerated (one malformed row
  does not abort the load).
- **Internal/Services/GitHubCompareUrlBuilder** — composes the
  Compare URL the suggest modal opens. Branch slug is a deterministic
  `sha256(pattern)[:16]` so the same suggestion always lands on the
  same proposed branch; body is the URL-encoded YAML snippet the user
  can paste into the PR composer.
- **Internal/Listeners/SeedCommunityCorpus** — listens for
  `Core::UserInstalled`; upserts every loaded entry into
  `community_merchant_mappings`. Idempotent via `updateOrInsert` keyed
  on `(pattern, user_id IS NULL)`.
- **Internal/Shell/NoOpShell** — fallback for the
  `Native\Desktop\Contracts\Shell` contract when the bundle is not
  running inside the NativePHP runtime (Herd dev mode, CI tests).
  Logs the would-be URL and does nothing.
- **Internal/Http/Livewire/** — `MysteryMerchantsPage` (the triage
  list), `SuggestMappingModal` (the suggest flow), `SharedListSettingsPanel`
  (the corpus opt-in toggles), `HelpOthersTriageButton` (the call-to-
  action surfaced from `/triage`).

## Key services + events

- `CorpusLoader::load()` — entry point for the seeder. Streams
  validated `CorpusEntryDto` instances; the loader never throws on
  per-entry failure (logs at `warning`, continues).
- `SeedCommunityCorpus::handle($event)` — runs at every signup AND at
  every install command re-run, so the upsert must be idempotent.
  Mirrors `Categorization::SeedDefaultCategoryTree` in shape.
- `CommunityCorpusQuery::findFor($pattern, $user)` — returns the
  matched corpus entry (per-user override beats global) or `null`.
  Pure read; never writes.
- `GitHubCompareUrlBuilder::build($dto)` — composes the Compare URL;
  branch slug is a deterministic hash of the pattern; body fields are
  YAML-double-quote-escaped so a name like `"Bob's Burgers"` round-
  trips cleanly.
- `OpenExternalUrlAction::__invoke($url)` — opens the URL via the
  injected `Shell` contract. Throws `InvalidArgumentException` for any
  URL that fails the HTTPS-scheme check or the `github.com` host
  allow-list.
- `MysteryMerchantSubmitted` event — dispatched by the modal on
  successful submit. No listener today; reserved for future
  contribution-volume aggregation.

## Data flow

The seed-at-signup flow:

```
UserInstalled
  → SeedCommunityCorpus::handle
       → CorpusLoader::load
            → read resources/corpus/merchant-mappings.yaml
            → read resources/corpus/built-in-heuristics.yaml
            → per entry: validate + PatternGeneralizer + CorpusEntryDto
       → for each DTO: updateOrInsert community_merchant_mappings
           keyed on (pattern, user_id IS NULL)
```

The suggest-a-mapping flow:

```
GET /community/mystery-merchants
  → MysteryMerchantsPage shows unidentified patterns
  → user clicks "Suggest a name"
     → SuggestMappingModal renders pre-filled fields
     → user types name + category + region
     → modal::submit()
          → GitHubCompareUrlBuilder::build($dto)
               → branch = "suggest-" + sha256($pattern)[:16]
               → body  = url-encoded YAML snippet
               → return base + "..." + branch + "?expand=1&body=..."
          → OpenExternalUrlAction::__invoke($url)
               → validate https + github.com
               → Shell::openExternal($url)
          → MysteryMerchantSubmitted dispatched
          → modal closes, toast confirms "Opened GitHub in your browser"
```

The import-preview consult flow (cross-module):

```
ImportPipeline.preview
  → NormalizeStage produces counterpartyNormalized
  → CommunityCorpusQuery::findFor($pattern, $user)
       → return per-user override OR global corpus row OR null
  → if hit: preview row offers the corpus name as a suggestion
            (user accepts explicitly during confirm)
```
