# `Community` — code

The file-level map for the module.

## Directory layout

```
Modules/Community/
├── Public/
│   ├── Actions/
│   │   └── OpenExternalUrlAction.php
│   ├── Dto/
│   │   ├── CorpusEntryDto.php
│   │   └── SuggestMappingDto.php
│   ├── Events/
│   │   └── MysteryMerchantSubmitted.php
│   └── Services/
│       └── CommunityCorpusQuery.php
├── Internal/
│   ├── Corpus/
│   │   └── CorpusLoader.php
│   ├── Services/
│   │   └── GitHubCompareUrlBuilder.php
│   ├── Listeners/
│   │   └── SeedCommunityCorpus.php
│   ├── Shell/
│   │   └── NoOpShell.php
│   └── Http/Livewire/
│       ├── MysteryMerchantsPage.php
│       ├── SuggestMappingModal.php
│       └── SharedListSettingsPanel.php
├── Models/
│   └── CommunityMerchantMapping.php
├── Database/
│   ├── Migrations/
│   │   └── 2026_05_26_000002_create_community_merchant_mappings_table.php
│   └── Seeders/
│       └── Demo/DemoCommunityMappingsSeeder.php
├── Routes/
│   └── web.php
├── Resources/
│   └── views/
├── Providers/
│   └── CommunityServiceProvider.php
└── tests/
    ├── Unit/
    │   └── OpenExternalUrlActionTest.php
    └── Feature/
```

The bundled corpus YAML lives at
`resources/corpus/merchant-mappings.yaml` and
`resources/corpus/built-in-heuristics.yaml` in the repo root, not inside
the module. The module reads them via `CorpusLoader`.

## Public API

- **Actions/**
  - `OpenExternalUrlAction::__invoke(string $url)` — wraps the
    NativePHP `Shell::openExternal` contract. Two-gate validation
    (HTTPS scheme; `github.com` allow-list). Throws
    `InvalidArgumentException` on either gate's failure.
- **DTOs/**
  - `CorpusEntryDto` — `(pattern, name, category, region, contributor,
    generalizedPattern)` value object emitted by `CorpusLoader`.
  - `SuggestMappingDto` — `(pattern, name, category, region)` payload
    feeding `GitHubCompareUrlBuilder`.
- **Events/**
  - `MysteryMerchantSubmitted` — `(SuggestMappingDto $dto, int $userId)`.
    No listener today; reserved.
- **Services/**
  - `CommunityCorpusQuery::lookupExact(string $rawDescription,
    ?string $region = null)`, `lookupGeneralized(...)`,
    `lookupRegex(...)` — each returns the matched merchant name or
    `null`. Region-scoped, not user-scoped: all three filter
    `user_id IS NULL` and read only the global tier.
  - `CommunityCorpusQuery::contactForMerchant(string $name)` — the
    `MerchantContactDto` for a merchant, or `null`.
  - `CommunityCorpusQuery::mappingsCount()` /
    `contributorsCount()` — corpus totals; neither takes a user.

## Internal services

- `Internal/Corpus/CorpusLoader` — reads + validates the bundled YAML
  files. Per-entry failure tolerated:
  - missing YAML file → warning + skip;
  - parse error → warning + skip;
  - missing required field on an entry → warning + skip that entry.
  Computes `generalizedPattern` via `PatternGeneralizer` from
  `Import`.
- `Internal/Services/GitHubCompareUrlBuilder::build($dto)` — composes
  the URL. Reads `config('community.github_compare_base')`
  (default `https://github.com/beatrax-app/beatrax/compare/main`),
  overridable via `BEATRAX_GITHUB_COMPARE_BASE`. Branch slug is
  `'suggest-' + substr(sha256($pattern), 0, 16)`. Body is a YAML
  snippet, every user-supplied field wrapped in double quotes with
  embedded `"`, `\`, `\n`, `\r`, `\t` escaped per YAML 1.2.
- `Internal/Listeners/SeedCommunityCorpus::handle($event)` — runs the
  loader, upserts each DTO into `community_merchant_mappings` keyed on
  `(pattern, user_id IS NULL)`. Per-entry failure tolerated.
- `Internal/Shell/NoOpShell` — fallback implementation of
  `Native\Desktop\Contracts\Shell` used outside the NativePHP runtime.
  Logs `Shell::openExternal` calls and returns without launching a
  browser.
- `Internal/Http/Livewire/MysteryMerchantsPage` — the
  `/community/mystery-merchants` triage list.
- `Internal/Http/Livewire/SuggestMappingModal` — the modal that
  composes a suggestion. DIs `OpenExternalUrlAction` into the
  `submit()` method.
- `Internal/Http/Livewire/SharedListSettingsPanel` — the corpus
  opt-in toggles surfaced under `/settings`. The per-row triage
  call-to-action is rendered by Categorization's own view, gated on the
  `offerToContribute` toggle these panels write.

## Models + migrations

- `Models/CommunityMerchantMapping` — maps to
  `community_merchant_mappings`. Uses `BelongsToUser`. Per-row
  contributors:
  - `contributor = 'beatrax-bot'` for bundled corpus rows
    (`user_id = NULL`);
  - `contributor = <username>` for per-user overrides.

Migrations:

- `2026_05_26_000002_create_community_merchant_mappings_table.php` —
  the table. Columns: `id`, `user_id` (nullable FK), `pattern`,
  `generalized_pattern`, `name`, `category` (nullable), `region`,
  `contributor`, timestamps. UNIQUE `(user_id, pattern)` keeps a
  single mapping per user-or-global tuple; index
  `(generalized_pattern)` supports the resolver's fuzzy scan.

## Provider wiring

`CommunityServiceProvider::register()`:

- Merges `config/community.php` under the `community` key.
- Singletons `CorpusLoader`, `CommunityCorpusQuery`,
  `GitHubCompareUrlBuilder`, `OpenExternalUrlAction`.
- Conditionally binds `Native\Desktop\Contracts\Shell` to
  `NoOpShell` if no other module (i.e. the NativePHP service
  provider in `Modules/Desktop`) has already bound it.

`CommunityServiceProvider::boot()`:

- Loads the migration, web routes (guarded by file-exists), views
  (guarded by dir-exists) — the file-guarded loads keep test boots
  green when a partial test harness elides routes or views.
- Subscribes `SeedCommunityCorpus` to `Core::UserInstalled`.
- Registers four Livewire components under the `community.*`
  namespace.
