# `Community` — code

The file-level map for the module.

## Directory layout

```
Modules/Community/
├── Public/
│   ├── Actions/
│   │   └── OpenExternalUrlAction.php
│   ├── Dto/
│   │   ├── ClassificationRule.php
│   │   ├── CorpusEntryDto.php
│   │   ├── MerchantContactDto.php
│   │   ├── SuggestMappingDto.php
│   │   └── SupportResource.php
│   ├── Enums/
│   │   └── CommunitySetting.php
│   ├── Events/
│   │   └── MysteryMerchantSubmitted.php
│   ├── Services/
│   │   ├── ClassificationRuleProvider.php
│   │   ├── CommunityCorpusQuery.php
│   │   ├── CommunitySettings.php
│   │   ├── CorpusPatternMatcher.php
│   │   └── SupportResourceProvider.php
│   └── Support/
│       ├── LoggableUrl.php
│       └── RecipientAddress.php
├── Internal/
│   ├── Corpus/
│   │   ├── CorpusLoader.php
│   │   ├── CorpusYamlReader.php
│   │   └── MerchantContactReader.php
│   ├── Services/
│   │   ├── ContributionLog.php
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
│   │   ├── 2026_05_26_000002_create_community_merchant_mappings_table.php
│   │   └── 2026_08_14_000003_add_contact_fields_to_community_merchant_mappings.php
│   └── Seeders/
│       └── Demo/DemoCommunityMappingsSeeder.php
├── Routes/
│   └── web.php
├── Resources/
│   ├── lang/          (26 locales × settings|index|suggest|mystery|triage)
│   └── views/
├── Providers/
│   └── CommunityServiceProvider.php
└── tests/
    ├── Unit/
    └── Feature/
```

The bundled corpus YAML lives outside the module, at the repo root under
`resources/corpus/`, one directory per kind and one file per country code:
`merchants/*.yaml` (the merchant-identification corpus), `support/*.yaml`
(the cancel/help/save resources), `government/*.yaml` and `bank-fees/*.yaml`
(the classification rules). `international.yaml` under `support/` and
`eu.yaml` under `merchants/` are the cross-border files, answered to every
country at once. The module reads them all through `CorpusYamlReader`.

## Public API

- **Actions/**
  - `OpenExternalUrlAction::__invoke(string $url)` — wraps the
    NativePHP `Shell::openExternal` contract. Two-gate validation
    (HTTPS scheme; `github.com` allow-list). Throws
    `InvalidArgumentException` on either gate's failure.
- **DTOs/**
  - `CorpusEntryDto` — `(pattern, name, category, region, contributor,
    generalizedPattern, contact)` value object emitted by `CorpusLoader`.
  - `MerchantContactDto` — the nullable contact half of a corpus row
    (website, cancelUrl, supportUrl, supportPhone, supportEmail).
  - `SuggestMappingDto` — `(pattern, name, region, category)` payload
    feeding `GitHubCompareUrlBuilder`. `region` is required: the caller
    resolves it from the reader's country, so there is no default to fall
    back to.
  - `SupportResource` — one support-corpus entry; every field but
    `name`/`type` optional. `mailtoHref()` builds the pre-filled
    cancellation `mailto:` only for a recipient `RecipientAddress`
    accepts.
  - `ClassificationRule` — one government / bank-fee keyword rule.
- **Enums/**
  - `CommunitySetting` — the `users.community_settings` keys
    (`useSharedList`, `offerToContribute`, `updateOnAppUpdates`) and each
    one's default.
- **Events/**
  - `MysteryMerchantSubmitted` — `(int $userId, string $pattern)`.
    No listener today; reserved.
- **Services/**
  - `CommunityCorpusQuery::lookupExact(string $rawDescription,
    ?string $region = null)`, `lookupGeneralized(...)`,
    `lookupRegex(...)` — each returns the matched merchant name or
    `null`. Region-scoped, not user-scoped: all three filter
    `user_id IS NULL` and read only the global tier.
  - `CommunityCorpusQuery::contactForMerchant(string $name)` — the
    `MerchantContactDto` for a merchant, or `null`.
  - `CommunityCorpusQuery::mappingsCount()` / `contributorsCount()` —
    corpus totals; `contributionsCount(int $userId)` — the reader's own
    filed suggestions.
  - `CommunitySettings::usesSharedList(int $userId)` /
    `offersToContribute(int $userId)` / `enabled(CommunitySetting,
    int $userId)` — the read side of the Settings toggles, and the
    surface a consumer in another module gates on.
  - `SupportResourceProvider::forCounterparty(string $name, string $type,
    ?string $country = null)` — the support/cancel resource for a
    counterparty, or `null`. Own country, then `international`, then a
    foreign file only when exactly one of them answers.
  - `ClassificationRuleProvider` — the bundled government + bank-fee
    keyword rules, per country, memoised per type.
  - `CorpusPatternMatcher::compileToken()` / `matchesCompiled()` /
    `containsToken()` / `matches()` — the literal whole-token and
    `regex:`-prefixed pattern kinds.
- **Support/**
  - `LoggableUrl::withoutQuery()` — a URL stripped of the query a log
    line must not keep.
  - `RecipientAddress::isSingle()` — the one gate on a cancellation
    recipient: an allow-list, so no separator (`,`, `;`, `%2C`) or
    header-forging character can walk past it.

## Internal services

- `Internal/Corpus/CorpusYamlReader` — path resolution + YAML parsing
  for every corpus consumer. Missing file, parse error or absent
  `entries:` root is a logged `warning`, never a throw.
- `Internal/Corpus/CorpusLoader` — reads + validates
  `resources/corpus/merchants/*.yaml`. Per-entry failure tolerated:
  - missing YAML file → warning + skip;
  - parse error → warning + skip;
  - missing required field on an entry → warning + skip that entry.
  Computes `generalizedPattern` via `PatternGeneralizer` from
  `Import`, and the contact half via `MerchantContactReader`.
- `Internal/Corpus/MerchantContactReader` — the single validation gate
  for a corpus row's contact fields (HTTPS + length for URLs, a shape
  check for phones, `RecipientAddress` for emails). Every rejection is a
  logged warning; `BundledCorpusIntegrityTest` fails the build on any.
- `Internal/Services/ContributionLog::record($userId, $contributor,
  $dto)` — the reader's own row for a filed suggestion, upserted on
  `(user_id, pattern)`. `created_at` is not in the update list, so
  correcting a suggestion keeps the date it was made.
- `Internal/Services/GitHubCompareUrlBuilder::build($dto)` — composes
  the URL. Reads `config('community.github_compare_base')`
  (default `https://github.com/beatrax-app/beatrax/compare/main`),
  overridable via `BEATRAX_GITHUB_COMPARE_BASE`. Branch slug is
  `'suggest-' + substr(sha256($pattern), 0, 16)`. Body is a YAML
  snippet, every user-supplied field wrapped in double quotes with
  embedded `"`, `\`, `\n`, `\r`, `\t` escaped per YAML 1.2.
- `Internal/Listeners/SeedCommunityCorpus::handle($event)` — runs the
  loader, then one id map over the global tier and a batched insert or
  per-row update into `community_merchant_mappings`, keyed on
  `(pattern, user_id IS NULL)`. Per-entry failure tolerated.
- `Internal/Shell/NoOpShell` — fallback implementation of
  `Native\Desktop\Contracts\Shell` used outside the NativePHP runtime.
  Logs `Shell::openExternal` calls (query stripped through
  `LoggableUrl`) and returns without launching a browser.
- `Internal/Http/Livewire/MysteryMerchantsPage` — the
  `/community/mystery-merchants` triage list.
- `Internal/Http/Livewire/SuggestMappingModal` — the modal that
  composes a suggestion. DIs `OpenExternalUrlAction` into the
  `submit()` method, and `UserCountry` into `mount()`, `open()`,
  `submit()` and `render()` — the first three to seed `$region` from the
  reader's own country, the last to build the dropdown from
  `UserCountry::options()`.
- `Internal/Http/Livewire/SharedListSettingsPanel` — the corpus
  opt-in toggles surfaced under `/community`. The per-row triage
  call-to-action is rendered by Categorization's own view, gated on the
  `offerToContribute` toggle this panel writes.

## Models + migrations

- `Models/CommunityMerchantMapping` — maps to
  `community_merchant_mappings`. The `BelongsToUser` trait is
  deliberately NOT applied: global rows must stay readable whoever is
  signed in, and the per-user reads filter at the call site. Per-row
  contributors:
  - `contributor = 'beatrax-bot'` for bundled corpus rows
    (`user_id = NULL`);
  - `contributor = <username>` for the reader's own filed suggestions.

Migrations:

- `2026_05_26_000002_create_community_merchant_mappings_table.php` —
  the table. Columns: `id`, `user_id` (nullable FK), `pattern`,
  `generalized_pattern`, `name`, `category` (nullable), `region`,
  `contributor`, timestamps. UNIQUE `(user_id, pattern)` keeps a
  single mapping per user-or-global tuple; index
  `(generalized_pattern)` supports the resolver's fuzzy scan.
- `2026_08_14_000003_add_contact_fields_to_community_merchant_mappings.php`
  — `website`, `cancel_url`, `support_url`, `support_phone`,
  `support_email`, all nullable.

## Provider wiring

`CommunityServiceProvider::register()`:

- Merges `config/community.php` under the `community` key.
- Singletons `CorpusYamlReader`, `CorpusLoader`, `CommunityCorpusQuery`,
  `ClassificationRuleProvider`, `CorpusPatternMatcher`,
  `SupportResourceProvider`, `GitHubCompareUrlBuilder`,
  `OpenExternalUrlAction`.
- Conditionally binds `Native\Desktop\Contracts\Shell` to
  `NoOpShell` if no other module (i.e. the NativePHP service
  provider in `Modules/Desktop`) has already bound it.

`CommunityServiceProvider::boot()`:

- Loads the migration, web routes (guarded by file-exists), views
  (guarded by dir-exists) — the file-guarded loads keep test boots
  green when a partial test harness elides routes or views.
- Re-asserts the `NoOpShell` binding outside the live NativePHP
  runtime, because `NativeServiceProvider` binds the real one
  unconditionally at register time.
- Subscribes `SeedCommunityCorpus` to `Core::UserInstalled`.
- Registers the three Livewire components under the `community.*`
  namespace.
