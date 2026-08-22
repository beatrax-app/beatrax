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
    category, region, contributor, generalized_pattern, contact).
  - `MerchantContactDto` — the optional contact/cancellation half of a
    corpus row (website, cancel_url, support_url, support_phone,
    support_email). Nested inside `CorpusEntryDto` rather than flattened
    into it, so the "how do I leave this?" data travels as one nullable
    unit and the entry DTO keeps a readable constructor.
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
  `community_merchant_mappings`. Idempotent, keyed on `(pattern,
  user_id IS NULL)`. It reads the existing global tier as a single
  pattern→id map and batches the inserts in chunks, because at one
  SELECT+INSERT per row a six-thousand-entry corpus is the slowest
  thing that happens during signup. `created_at` is written only on
  the insert path, so a re-dispatch preserves the original seed
  timestamp; the contact columns are written on both paths, nulls
  included, so a field a contributor removes from the YAML is cleared
  rather than left behind as a stale cancellation link. A chunk that
  fails is retried row by row, keeping the original guarantee that one
  malformed entry never costs its neighbours their seed.
- **Internal/Shell/NoOpShell** — fallback for the
  `Native\Desktop\Contracts\Shell` contract when the bundle is not
  running inside the NativePHP runtime (local dev mode, CI tests).
  Logs the would-be URL and does nothing.
- **Internal/Http/Livewire/** — `MysteryMerchantsPage` (the triage
  list), `SuggestMappingModal` (the suggest flow), `SharedListSettingsPanel`
  (the corpus opt-in toggles).

## Key services + events

- `CorpusLoader::loadBundled()` — entry point for the seeder. Returns
  the validated `CorpusEntryDto` list read from every YAML file under
  the bundled merchants corpus; the loader never throws on per-entry
  failure (logs at `warning`, continues).
- `SeedCommunityCorpus::handle($event)` — runs at every signup AND at
  every install command re-run, so the upsert must be idempotent.
  Mirrors `Categorization::SeedDefaultCategoryTree` in shape.
- `CommunityCorpusQuery::lookupExact($rawDescription, $region)`,
  `lookupGeneralized(...)`, `lookupRegex(...)` — the three read arms,
  each returning the matched merchant name or `null`. Pure reads; they
  never write. The scope is the REGION, not the user: every arm filters
  `user_id IS NULL`, so only the global tier is ever consulted. The
  per-user override tier that the table's `unique(['user_id',
  'pattern'])` index anticipates has neither a write path nor a read
  path today, and the query has read the global tier alone since the
  module shipped — treat the column as reserved, not as a resolution
  rule. Region scoping is what earns its keep: consulting every
  country's corpus at once resolved a Dutch `Albert Heijn 1042` to the
  Czech chain ALBERT, because `cz.yaml` seeds before `nl.yaml` and won
  the first-match scan on the lower id.
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
       → CorpusLoader::loadBundled
            → glob resources/corpus/merchants/*.yaml, sorted by filename
              (the filename is the region code the entries default to)
            → per entry: validate + PatternGeneralizer + CorpusEntryDto
       → one id map over the global tier, then per DTO either an
           UPDATE of the existing row or a batched INSERT
           (500 rows per chunk) into community_merchant_mappings
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

## Corpus support layers

`CorpusYamlReader` (`Internal/Corpus/`) is the shared filesystem + YAML access
layer both `CorpusLoader` and `ClassificationRuleProvider` sit on top of, so
path resolution and the YAML threat model (`PARSE_EXCEPTION_ON_INVALID_TYPE`
— no native-tag object instantiation) live in one place rather than two
divergent copies. Every failure — missing file, malformed YAML, no `entries:`
root — is tolerated and logged at `warning`, never thrown; one bad file can
never abort the whole corpus load. `resolve()` supports a `community.app_root`
config override so a test can point the loader at a fixture directory, and
treats an empty result as "no path configured" uniformly across every
consumer.

`ClassificationRuleProvider` loads the bundled, non-user-contributable
government-agency and bank-fee keyword rules from
`resources/corpus/<type>/<country>.yaml`, backing the `MerchantNameResolver`'s
government and bank-fee classification steps. Splitting these out of hardcoded
PHP constants lets a contributor add another country's rules — including
`regex:` patterns — by dropping in a YAML file, no code change. Each file's
country is inferred from its filename; results are memoised per-type for the
life of the (singleton) instance.

`MerchantContactReader` (`Internal/Corpus/`) parses the optional contact and
cancellation keys off a merchant entry and is the single validation gate for
them. A URL must be `https://` (these are links a user clicks to end a real
contract, so a downgradeable scheme is a hazard, not a style question), must
parse, and must fit the 512-character column verbatim — a value that would be
truncated is dropped instead, because a half-URL is a broken cancellation
route. A phone number is kept in the merchant's own published notation rather
than normalised to E.164: the numbers people actually need (0800/0900 service
lines, three-digit short codes) have no E.164 form, so normalising would throw
away the most useful half of the data; a shape check is what stops free text
reaching a `tel:` href. An email must validate AND carry no `?`, `&` or
whitespace — all three are legal RFC 5322 atext that `FILTER_VALIDATE_EMAIL`
accepts but that forge extra headers inside a `mailto:`, the same guard
`SupportResource::mailtoHref` applies to the support corpus, moved one layer
earlier so a bad value never lands in the column at all.

Every rejection is a logged warning, never a throw, matching the rest of the
corpus pipeline. That tolerance is what makes `BundledCorpusIntegrityTest`
necessary: it replays the whole bundled corpus through the real reader and
fails the build on any warning, so a contributor's typo surfaces as a red test
instead of a link that silently never renders. The same test enforces global
pattern uniqueness, because the global tier is keyed on `pattern` alone — two
country files sharing one pattern do not both seed, the later file's row
overwrites the earlier one, including overwriting its contact data with nulls.
Cross-border brands therefore live once in `merchants/eu.yaml`, not once per
country. Where the shared token belongs to two genuinely unrelated companies
(Maxi is a Quebec grocer and a Serbian one; ATB is a Norwegian transit
operator and a Ukrainian grocer) the collision is resolved by keeping a single
row with no `website`, because sending one country's customer to the other's
site is precisely the harm the contact fields exist to avoid.

`CorpusPatternMatcher` distinguishes two pattern kinds by an optional
`regex:` prefix. A literal pattern is a case-insensitive **whole-token** test,
not a bare substring one: `compileToken()` quotes the needle and fences it with
a `(?<![\p{L}\p{N}])` / `(?![\p{L}\p{N}])` lookaround on each edge whose own
character is alphanumeric, so `OBI` no longer matches inside `mobiel` while
`AMAZON.` still matches `AMAZON.NL`. A `regex:` pattern strips the prefix,
wraps the remaining PCRE body in `#...#i`, and tests it against the whole
haystack. A malformed regex never throws — `@preg_match` failure is logged once
at `warning` and treated as a non-match, so one bad corpus row can never abort
an import or a resolver pass.

Both kinds split the work that depends on the pattern alone from the work that
depends on the description. `compileToken()` returns the finished pattern (or
`null` for a needle that can never match anything, which is what lets a caller
drop the row), and `matchesCompiled()` is the only half a scan repeats;
`containsToken()` is the two called back to back, for a caller holding one
needle and one haystack. The `regex:` side memoises its length-cap and
compile-probe verdict per pattern on the instance, so a bad corpus row is judged
— and warned about — once rather than once per line scanned.

`SupportResourceProvider` loads `resources/corpus/support/*.yaml` and matches
counterparty names against the "where do I get help / cancel / save money"
corpus for the profile page. Matching is word-based, not substring: both the
resource name and the queried name are reduced to a lower-cased word list
with legal-entity suffixes (BV/NV/Inc/AB/…) dropped, and a resource matches
when its words are a leading prefix of the counterparty's words — so
"Netflix" matches "Netflix International BV" without letting "Apple" match
"Applebee's". The longest (most specific) matching resource wins. Brand
words like "Premium" are deliberately NOT stripped, since they distinguish a
subscription tier from the base brand. `SupportResource::mailtoHref()`
refuses to build a `mailto:` link when the stored recipient carries `?`, `&`,
or whitespace/CR/LF — defence against header/recipient injection into the
pre-filled cancellation email.

## Read-side query mechanics

`CommunityCorpusQuery` (consumed by `MerchantNameResolver`'s community-tail
fallback steps, the mystery-merchants stats strip, and the suggest-mapping
dedup check) exposes three lookup methods against the global corpus tier
(`user_id IS NULL`): `lookupExact()` (verbatim pattern match), `lookupGeneralized()`
(whole-token match against `generalized_pattern`, walked in PHP through
`CorpusPatternMatcher` — never SQL `LIKE` — so a malicious YAML entry carries
no SQL-wildcard injection surface), `contactForMerchant()` (the contact/cancellation card for
a resolved merchant, keyed on the corpus NAME rather than the descriptor: a
brand reaches the corpus through many descriptor variants that all collapse to
one name, and a profile page holds the name, never the row that matched), and
`lookupRegex()` (delegates to `CorpusPatternMatcher`
for rows whose pattern carries the `regex:` prefix — the `like 'regex:%'`
operand is a fixed constant, never a corpus value, so this SQL `LIKE` carries
no injection surface of its own).

Neither scan is capped. The `LIMIT 1000` / `LIMIT 500` these queries once
carried read as defence-in-depth against corpus growth but did the opposite:
ordered by `id` — which is bundled-file sort order — a cap does not sample the
corpus, it truncates it. Once the corpus outgrew the cap every pattern past
`ee.yaml` became unmatchable, taking the whole of `eu.yaml` with it, so
Netflix, Spotify, Vodafone and Lidl silently stopped resolving and the raw
descriptor stayed on screen. A bound that changes answers without saying so
costs more than the work it saves — the scan is one match per row against one
haystack, linear either way — so the rows are read, case-folded and **compiled**
once per region key and matched in PHP, which is what keeps the repeated lookups
off the database during an import.

Compiling at load is what makes the uncapped scan affordable. The needle-only
half of a token match — the UTF-8 check, the "has a word character at all"
check, the two edge probes and `preg_quote` — is four regular-expression
operations that depend on the corpus row and not on the description, so running
them inside the per-description loop multiplied the whole corpus by five.
`nl.yaml`'s 294 generalized needles cost 1,176 regex operations per description
scanned that way and 294 compiled; a reader who has named no country pays that
against the whole 11k-row corpus. The compiled pattern *replaces* the needle in
the memo rather than joining it, and a needle that compiles to `null` is dropped
there, so the memo does not grow to buy the speed.

## Settings, triage, and the suggest flow

`SharedListSettingsPanel` renders the three Settings toggles: "Use the shared
merchant list" (gates whether `MerchantNameResolver` consults the community
tier), "Offer to contribute" (gates the triage row's CTA), and "Update the
shared list on app updates" — the third toggle ships disabled: its handler is
an intentional no-op that writes nothing to `users.community_settings`,
protecting the column from a forged Livewire call while the live-update
mechanism itself waits on a future app update. Toggle state lives in the
`users.community_settings` JSON column, read/written directly rather than
through a separate settings model.

The per-row "Help others identify this" CTA lives in Categorization's
triage view and is gated SERVER-SIDE on the same `offerToContribute` toggle:
`TriageInbox::render()` resolves it and the Blade wraps the button in an
`@if`, so with the toggle off the control is structurally absent from the DOM,
not merely CSS-hidden. Structural absence is the mitigation for the
unauthorized-contribution threat: a client-side-hidden control would still be
DOM-reachable and dispatchable. Clicking it dispatches `suggest-mapping:open`
with the row's verbatim raw description so the single globally-mounted
`SuggestMappingModal` opens prefilled.

`SuggestMappingModal` is mounted once at the layout level so `suggest-mapping:open`
can open it from anywhere (the triage CTA, a mystery card, the Settings
"Browse mystery merchants" link). On submit it builds a `SuggestMappingDto`
(region defaults to `'NL'` since the bundled corpus targets Dutch banks; the
modal's dropdown lets the user override), resolves the Compare URL via
`GitHubCompareUrlBuilder`, and hands it to `OpenExternalUrlAction`; if that
action throws (e.g. a tampered config value pointing at a non-allow-listed
host), the modal stays open with the error rendered inline rather than losing
the user's typed input. Only on a successful launch does it dispatch
`MysteryMerchantSubmitted` (carrying the user id + verbatim submitted
pattern) — never on the failure branch — so a listener can trust the event
to mean "the system browser actually opened."

`SupportResource` (the support/cancel/help corpus entry rendered on the
counterparty profile) has every field optional — a profile renders only the
links that exist; merchant entries lean on cancel/support/cheaper + an
optional pre-written cancellation email, government entries lean on
help/apply/rights + a phone number.

## `OpenExternalUrlAction` defence-in-depth

Every URL passed to `OpenExternalUrlAction` is validated through two gates
before it reaches the `Shell` contract. The first gate rejects any non-HTTPS
URL (`http://`, `javascript:`, `file://`, or any scheme `filter_var`'s
validator accepts but is not a web fetch). The second restricts the host to
an allow-list — currently only `github.com`, since the sole outbound surface
this module introduces is the Compare URL `GitHubCompareUrlBuilder` produces.
Outside the live NativePHP runtime the container resolves `Shell` to the
in-module `NoOpShell` fallback, which logs the would-be URL and does nothing
else; feature tests bind a `ShellFake` to assert intent without launching a
real browser.

## Demo seeding

`DemoCommunityMappingsSeeder` materialises three per-user override rows
(`user_id = $primary->id`) for the primary demo user, deliberately choosing
patterns that do NOT collide with the bundled global corpus — the
`(user_id, pattern)` UNIQUE lets a global row and a per-user row share the
same pattern, and the seeder exercises that per-user-override branch of the
resolver on purpose. It is idempotent via `updateOrCreate` keyed on
`(user_id, pattern)`, matching the table's UNIQUE constraint.

## NativePHP Shell binding

`CommunityServiceProvider::boot()` force-rebinds `Native\Desktop\Contracts\Shell`
to `NoOpShell` whenever the app is not running inside the live NativePHP
desktop runtime. This is necessary because NativePHP's own
`NativeServiceProvider` unconditionally binds the real `Shell` during its
`register()` phase (`packageRegistered`), so this module's own `register()`-time
`! bound()` guard never wins once the package is installed — outside the
live desktop runtime that real implementation POSTs to the Electron bridge on
`localhost:4000`, which isn't running, and every `openExternal()` call would
throw a `ConnectionException`. Doing the rebind in `boot()` guarantees it wins
regardless of provider registration order, and is safe because nothing
resolves `Shell` during boot — only at request/click time.

## The `community_merchant_mappings` table

Rows with `user_id IS NULL` are global corpus entries seeded from the bundled
YAML at install time; rows with a non-null `user_id` are per-user overrides
where the user supplied their own friendly name for a pattern. The
`BelongsToUser` trait is intentionally NOT applied to the model — global rows
must remain readable regardless of the authenticated user, and per-user
override reads filter explicitly at the call site instead (mirrors
`SystemAlert`'s identical global-vs-scoped shape).

## Mystery-merchants stats

`MysteryMerchantsPage::render()` scans up to 2,000 of the user's most recent
transactions, groups every description the `MerchantNameResolver` cannot
identify, and renders the top 24 by occurrence count as mystery cards. The
stats strip's `contributorCount` KPI is currently a hardcoded zero — there is
no contribution-tracking listener on `MysteryMerchantSubmitted` yet — so the
slot renders consistently with its eventual real value rather than being
omitted.

The auto-named KPI is a percentage of the rows this device could **read**,
not of the rows it scanned. A description that decrypts to nothing is counted
as unreadable and drops out of both halves of the fraction; when that leaves
no readable rows at all the KPI is `null` and the strip renders an em dash.
Counting a blanked row as resolved is what made a device with no keyring show
zero mysteries and "100 % auto-named" — a perfect score over a page that had
in fact read nothing.
