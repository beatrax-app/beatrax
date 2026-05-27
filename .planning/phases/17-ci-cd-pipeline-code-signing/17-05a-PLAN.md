---
phase: 17-ci-cd-pipeline-code-signing
plan: 05a
type: execute
wave: 1
depends_on: []
files_modified:
  - Modules/Counterparties/composer.json
  - Modules/Counterparties/module.json
  - Modules/Counterparties/Providers/CounterpartiesServiceProvider.php
  - Modules/Counterparties/Routes/web.php
  - Modules/Counterparties/Models/Counterparty.php
  - Modules/Counterparties/Public/Contracts/CounterpartyResolver.php
  - Modules/Counterparties/Public/Dto/CounterpartyResolutionDto.php
  - Modules/Counterparties/Public/Events/CounterpartyResolved.php
  - Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php
  - Modules/Counterparties/Database/Migrations/XXXX_create_counterparties_table.php
  - Modules/Counterparties/Database/Migrations/XXXX_add_counterparty_id_to_transactions.php
  - Modules/Counterparties/tests/Unit/CounterpartyResolverTest.php
  - Modules/Counterparties/tests/Unit/PrivacyDefaultsTest.php
  - Modules/Counterparties/tests/Unit/SlugCollisionTest.php
  - config/app.php
  - bootstrap/providers.php
autonomous: true
requirements:
  - gap-counterparty-module-backend-scaffold
requirements_addressed:
  - gap-counterparty-module-backend-scaffold
must_haves:
  truths:
    - "Modules/Counterparties/ bounded module is registered + boots cleanly"
    - "counterparties table exists with type enum (merchant|personal|bank|government|self_account|unknown) enforced by paired BEFORE INSERT/UPDATE triggers"
    - "transactions.counterparty_id nullable FK column added with index on (user_id, counterparty_id)"
    - "CounterpartyResolver Public contract resolves a CanonicalTransaction → CounterpartyResolutionDto via the 7-step chain"
    - "Personal-type slugs are display-name-only (no IBAN suffix); IBAN never written to slug column"
    - "Self-account check (step 1 of resolution) routes user's own account IBANs to type=self_account without creating a Counterparty row"
    - "Slug collision falls back to {slug}-2, {slug}-3, ... pattern; no slug ever duplicated per (user_id, slug)"
  artifacts:
    - path: "Modules/Counterparties/Database/Migrations/XXXX_create_counterparties_table.php"
      provides: "counterparties table with type enum + triggers"
      contains: "CREATE TRIGGER counterparties_type_check"
    - path: "Modules/Counterparties/Database/Migrations/XXXX_add_counterparty_id_to_transactions.php"
      provides: "Nullable FK on transactions; cascadeOnDelete from user (NOT from counterparty per D-45)"
    - path: "Modules/Counterparties/Models/Counterparty.php"
      provides: "Eloquent model with BelongsToUser global scope, type cast, metadata JSON cast"
    - path: "Modules/Counterparties/Public/Contracts/CounterpartyResolver.php"
      provides: "DI contract consumed by ImportPipeline + Ledger + Recurring + Chains + Categorization"
      exports: ["resolve(CanonicalTransaction $tx, User $user): ?CounterpartyResolutionDto"]
    - path: "Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php"
      provides: "7-step resolution chain implementation"
  key_links:
    - from: "CounterpartyResolverService step 2"
      to: "Modules/Import/Public/Contracts/ResolvesKnownCounterpartyIban"
      via: "DI consumption — the alias bridge from Phase 16.1.2.1 resolves PayPal LU / ICS ABN IBANs to type=bank"
      pattern: "ResolvesKnownCounterpartyIban"
    - from: "CounterpartyResolverService step 3"
      to: "Modules/Import/Public/Services/MerchantNameResolver"
      via: "DI consumption — the merchant-name resolver from Phase 16.1 owns merchant identity"
      pattern: "MerchantNameResolver"
---

<objective>
Ship the Counterparties module scaffold + schema + 7-step resolver service + unit-test coverage of every resolution branch.

Purpose: Backend foundation for the Counterparties feature (A-04). This plan establishes the module, the database shape, the Public contract that consumers will DI, and the resolver service that implements the 7-step chain — all verified by Pest unit tests against fixture data. Plan 17-05b wires this resolver into ImportPipeline + adds the GC job + adds the boundary arch invariant + adds an end-to-end Feature test.

The 7-step resolution chain (CONTEXT.md Section I, verbatim):
  1. Self-account check — if target IBAN matches one of the user's own accounts → type=self_account, route to account view, DO NOT create a Counterparty row
  2. Known-counterparty-IBAN bridge (consume `Modules/Import/Public/Contracts/ResolvesKnownCounterpartyIban` from Phase 16.1.2.1) → type=bank
  3. Merchant resolution via `Modules/Import/Public/Services/MerchantNameResolver` → type=merchant
  4. Personal-IBAN heuristic (Dutch IBAN parser; personal name; appears in transfer_out/transfer_in) → type=personal (PRIVACY DEFAULTS — slug uses display name only, IBAN never in slug)
  5. Government keyword fallback (BELASTINGDIENST, GEMEENTE, RDW, CJIB) → type=government
  6. Description-keyword bank-fee fallback (KOSTEN KASOPNAME, RENTE, fee patterns) → type=bank (bank-fee subcategory)
  7. Unresolved → type=unknown, IBAN preserved for Triage queue

Output: A green `Modules/Counterparties/` module with schema migrated + resolver fully implemented + 14 unit-test behaviors green. NO ImportPipeline integration yet (that's 17-05b's job — keeps this plan's blast radius tight).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/17-ci-cd-pipeline-code-signing/17-CONTEXT.md
@.planning/phases/17-ci-cd-pipeline-code-signing/17-RESEARCH.md
@.planning/phases/17-ci-cd-pipeline-code-signing/17-PATTERNS.md
@Modules/Import/Public/Contracts/ResolvesKnownCounterpartyIban.php
@Modules/Import/Internal/Services/KnownCounterpartyIbanResolver.php
@Modules/Import/Models/KnownCounterpartyIban.php
@Modules/Import/Public/Services/MerchantNameResolver.php
@Modules/Onboarding/Providers/OnboardingServiceProvider.php
@Modules/Onboarding/Models/WizardProgress.php
@Modules/Categorization/Database/Migrations/2026_05_17_010003_create_categorization_rules_table.php
@Modules/Categorization/Public/Contracts/AppliesAutoCategory.php

<interfaces>
<!-- DEPENDENCY ON Phase 16.1.2.1 (LANDED) -->
namespace Modules\Import\Public\Contracts;
interface ResolvesKnownCounterpartyIban
{
    /**
     * Look up a counterparty IBAN against the known-bridge table.
     * Returns null if the IBAN is not a known synthetic / bank-owned IBAN.
     * Returns the resolved Account-kind (paypal|ics_card|...) when known.
     */
    public function resolve(string $counterpartyIban, int $userId): ?KnownCounterpartyIbanResolution;
}

<!-- DEPENDENCY ON Phase 16.1 (LANDED) -->
namespace Modules\Import\Public\Services;
final class MerchantNameResolver {
    public function resolve(string $rawDescription, int $userId): ?string;
}

<!-- DTO for resolution result -->
namespace Modules\Counterparties\Public\Dto;
final readonly class CounterpartyResolutionDto {
    public function __construct(
        public string $type,            // merchant|personal|bank|government|self_account|unknown
        public string $displayName,
        public string $slug,            // empty string when type === 'self_account'
        public ?string $iban,
        public ?string $merchantName,
        public array $metadata,         // associative array — JSON-cast on the model
        public ?int $counterpartyId,   // null when type === 'self_account' (no row created) OR when caller will upsert
    ) {}
}

<!-- The Public contract -->
namespace Modules\Counterparties\Public\Contracts;
interface CounterpartyResolver {
    public function resolve(CanonicalTransaction $tx, User $user): ?CounterpartyResolutionDto;
}
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Module scaffold + schema migrations + Counterparty model + DTO + Event</name>
  <files>Modules/Counterparties/composer.json, Modules/Counterparties/module.json, Modules/Counterparties/Providers/CounterpartiesServiceProvider.php, Modules/Counterparties/Routes/web.php, Modules/Counterparties/Models/Counterparty.php, Modules/Counterparties/Public/Dto/CounterpartyResolutionDto.php, Modules/Counterparties/Public/Events/CounterpartyResolved.php, Modules/Counterparties/Database/Migrations/XXXX_create_counterparties_table.php, Modules/Counterparties/Database/Migrations/XXXX_add_counterparty_id_to_transactions.php, config/app.php, bootstrap/providers.php</files>
  <read_first>
    - Modules/Onboarding/composer.json + Modules/Onboarding/module.json (analogs — copy structure verbatim per PATTERNS.md Area 5)
    - Modules/Onboarding/Providers/OnboardingServiceProvider.php (analog — register + boot pattern)
    - Modules/Onboarding/Models/WizardProgress.php (analog — BelongsToUser, fillable, casts())
    - Modules/Categorization/Database/Migrations/2026_05_17_010003_create_categorization_rules_table.php (analog — module migration with type-enum + paired triggers)
    - Modules/Ledger/Database/Migrations/2026_05_13_010003_add_enriched_count_to_import_runs.php (analog — additive-column migration)
  </read_first>
  <behavior>
    Migration + scaffold smoke (verified inline; no unit-test file yet):
    - `php artisan migrate --pretend` succeeds for both new migrations
    - After migrate: `counterparties` table exists with the type enum trigger pair
    - After migrate: `transactions.counterparty_id` column exists, nullable, with `(user_id, counterparty_id)` index
    - `php artisan about` lists `CounterpartiesServiceProvider` (provider registered + boots without error)
  </behavior>
  <action>Step A — Module scaffolding: create `Modules/Counterparties/{composer.json,module.json}` copying the Onboarding analogs verbatim with name swaps. Register `Modules\Counterparties\Providers\CounterpartiesServiceProvider` in `bootstrap/providers.php` (or `config/app.php` providers array — discover which the project uses). Provider's `register()` binds `CounterpartyResolver::class` → `CounterpartyResolverService::class` as a singleton (the implementation lands in Task 2 of THIS plan — bind anyway to fail-loud if the class is missing at boot). `boot()` loads migrations from `__DIR__/../Database/Migrations`, loads views from `__DIR__/../Resources/views` namespaced `counterparties`, loads routes from `__DIR__/../Routes/web.php` (Plan 17-06 fills the routes; for now create the file with a placeholder `<?php declare(strict_types=1);` to satisfy the load), and registers no Livewire components yet (Plan 17-06 owns the components).

    Step B — Migrations: create `XXXX_create_counterparties_table.php` mirroring the Categorization-rules migration EXACTLY (container-DI Migrator, paired triggers). Columns per PATTERNS.md Area 5: `id`, `user_id` (FK users cascadeOnDelete), `type` (varchar(16)), `slug` (varchar(128)), `display_name` (varchar), `iban` (varchar(64) nullable), `merchant_name` (varchar nullable), `metadata` (json nullable), timestamps. Indexes: unique on `(user_id, slug)`, index on `(user_id, type)`. Triggers: `counterparties_type_check_insert` + `counterparties_type_check_update` raising on type ∉ {merchant, personal, bank, government, self_account, unknown}.
    Create the second migration `XXXX_add_counterparty_id_to_transactions.php`: add nullable `counterparty_id` column (no FK constraint pointing at counterparties — the cascade is from `user_id` only per D-45 so orphaned counterparties don't auto-delete transactions); add index on `(user_id, counterparty_id)`.

    Step C — Model: `Counterparty.php` extending Eloquent Model, `BelongsToUser` trait, `$table='counterparties'`, `$fillable=['user_id','type','slug','display_name','iban','merchant_name','metadata']`, `casts()` returning `metadata=>'array', created_at=>'immutable_datetime', updated_at=>'immutable_datetime'`. Per PATTERNS.md include the cross-user-posture phpdoc block copied verbatim from `KnownCounterpartyIban.php`.

    Step D — DTO + Event: `CounterpartyResolutionDto` as `final readonly class` matching the interface contract above. `CounterpartyResolved` event as a `final readonly class` with public readonly props `(int $counterpartyId, int $userId, string $type)` — fired by the resolver after upsert (consumed by future cross-module surfaces; ZERO listeners ship in this plan).

    Throughout: DI-only, BelongsToUser on the model, PHPDocs describe present-tense behavior with no GSD/phase vocabulary.</action>
  <verify>
    <automated>php artisan migrate --pretend && php artisan about 2>&1 | grep -q CounterpartiesServiceProvider</automated>
  </verify>
  <done>Module loads via `php artisan about`; both migrations `--pretend` cleanly; model + DTO + event files materialize with the correct shape; Larastan L10 strict + Pint green; no GSD vocabulary leakage.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: CounterpartyResolver Public contract + CounterpartyResolverService implementation + 14 unit-test behaviors</name>
  <files>Modules/Counterparties/Public/Contracts/CounterpartyResolver.php, Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php, Modules/Counterparties/tests/Unit/CounterpartyResolverTest.php, Modules/Counterparties/tests/Unit/PrivacyDefaultsTest.php, Modules/Counterparties/tests/Unit/SlugCollisionTest.php</files>
  <read_first>
    - Modules/Import/Public/Contracts/ResolvesKnownCounterpartyIban.php + Modules/Import/Internal/Services/KnownCounterpartyIbanResolver.php (DEPENDENCY from Phase 16.1.2.1 — understand the resolve() return type so step 2 of the chain consumes it correctly)
    - Modules/Import/Public/Services/MerchantNameResolver.php (DEPENDENCY from Phase 16.1 — understand the resolve() return type for step 3)
    - Modules/Categorization/Public/Contracts/AppliesAutoCategory.php (analog — Public contract shape per PATTERNS.md)
    - Modules/Onboarding/tests/Unit/ResumeStepResolverTest.php (analog — Pest test scaffolding)
    - .planning/phases/17-ci-cd-pipeline-code-signing/17-PATTERNS.md (every Counterparty analog enumerated in Area 5)
  </read_first>
  <behavior>
    Unit tests in `CounterpartyResolverTest.php` cover the 7-step chain:
    - Test 1 (step 1 — self_account): given a CanonicalTransaction whose counterparty_iban matches one of the user's own Account.iban values, resolve() returns CounterpartyResolutionDto with type='self_account' and counterpartyId=null (no row created)
    - Test 2 (step 2 — bank via alias bridge): given a transaction with counterparty_iban='LU89751000135104200E' (PayPal SARL, seeded in 16.1.2.1), resolve() returns type='bank' with displayName matching the bridge resolution + a Counterparty row IS upserted
    - Test 3 (step 3 — merchant): given a transaction with a Netflix-shaped description, resolve() returns type='merchant' with displayName='Netflix' (via MerchantNameResolver) + Counterparty row upserted
    - Test 4 (step 4 — personal): given a transaction with a personal-name counterparty + IBAN + transfer_in/transfer_out type, resolve() returns type='personal' with displayName matching the name + slug=kebab-case(name) — IBAN NEVER in slug
    - Test 5 (step 5 — government): given a transaction with description containing 'BELASTINGDIENST', resolve() returns type='government'
    - Test 6 (step 6 — bank fee): given a description like 'KOSTEN KASOPNAME', resolve() returns type='bank' (subcategory bank-fee — encoded in metadata['subcategory']='fee')
    - Test 7 (step 7 — unknown): given a transaction with no match across 1-6, resolve() returns type='unknown' with the IBAN preserved in iban field
    - Test 8 (idempotency): calling resolve() twice on the same transaction returns the SAME counterpartyId (no duplicate row)
    - Test 9 (cross-user isolation): user A's resolve() never touches user B's counterparties (BelongsToUser-scoped queries throughout)

    `PrivacyDefaultsTest.php`:
    - Test 1: a personal-type Counterparty's slug is the kebab-case display name with NO IBAN suffix (e.g., 'maria-van-buren', not 'maria-van-buren-nl12abn0123456789')
    - Test 2: a personal-type Counterparty's iban column IS populated (the data exists) but never leaks into the slug column

    `SlugCollisionTest.php`:
    - Test 1: two distinct merchants both resolving to display name "Bol" produce slugs "bol" and "bol-2"
    - Test 2: a third "Bol" produces "bol-3"
    - Test 3: collision suffixing is per-user (user A's "bol" does NOT block user B's "bol")
  </behavior>
  <action>Step A — Public contract: create `Modules/Counterparties/Public/Contracts/CounterpartyResolver.php` matching the interface in the `<interfaces>` block above. Single method `resolve(CanonicalTransaction $tx, User $user): ?CounterpartyResolutionDto`.

    Step B — Resolver service: `CounterpartyResolverService` implementing the 7-step chain. Constructor DI: `DatabaseManager $db`, `ResolvesKnownCounterpartyIban $aliasBridge`, `MerchantNameResolver $merchantResolver`, `Dispatcher $events`. Method `resolve(CanonicalTransaction $tx, User $user): ?CounterpartyResolutionDto` walks the 7 steps in order. Step 1 queries `accounts` table for the user's own IBANs (raw query builder with explicit `->where('user_id', $user->id)`); if hit, returns DTO with type='self_account', counterpartyId=null, slug=''. Steps 2-6 each upsert a Counterparty row via `Counterparty::query()->firstOrCreate(['user_id'=>$user->id,'slug'=>$resolvedSlug], [...])` then fire `CounterpartyResolved`. Step 7 returns type='unknown' DTO with counterpartyId=upserted-id. Slug generation: kebab-case display name with collision suffixing (`bol`, `bol-2`, `bol-3`) — implement via a private `nextAvailableSlug(int $userId, string $base): string` helper. Personal-type slug is display-name-only (PrivacyDefaultsTest covers this). Personal-IBAN heuristic in step 4 uses a simple Dutch-IBAN regex (`/^NL\d{2}[A-Z]{4}\d{10}$/`) AND a "looks like a personal name" check (display name has 1-3 word tokens, no merchant suffix indicators like `BV`, `B.V.`, `LTD`, `INC`). Government keyword set in step 5: ['BELASTINGDIENST','GEMEENTE','RDW','CJIB','SVB']. Bank-fee patterns in step 6: ['KOSTEN KASOPNAME', 'RENTE', 'KOSTEN '].

    Step C — Pest tests: write three test files per behavior section above using `RefreshDatabase` + beforeEach user creation pattern from the Onboarding analog. Tests resolve the service via `$this->app->make(CounterpartyResolverService::class)` and arrange fixture transactions + accounts + alias rows directly via the query builder. Cross-user test (Test 9) creates two users and asserts user A's resolve never visits user B's data.

    Throughout: DI-only (no facades, no helpers); BelongsToUser on the model; explicit `->where('user_id', $userId)` on every raw query; PHPDocs describe present-tense behavior with no GSD/phase vocabulary.</action>
  <verify>
    <automated>vendor/bin/pest Modules/Counterparties/tests/Unit/ --stop-on-failure</automated>
  </verify>
  <done>All 9 + 2 + 3 = 14 unit-test behaviors pass; resolver fires CounterpartyResolved on every upsert; Larastan L10 strict + Pint green; PHPDocs free of GSD vocabulary; cross-user isolation test (Test 9) verifies BelongsToUser scoping.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| consumer code → CounterpartyResolver Public contract | Only entry point to the resolution chain; no raw Counterparty model access from other modules |
| MerchantNameResolver / KnownCounterpartyIbanResolver → CounterpartyResolverService | Both upstream resolvers are trusted DI'd services; their output is consumed without re-validation |
| counterparties table → resolver | All queries explicit `->where('user_id', ...)`; BelongsToUser is secondary scope |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-05a-01 | Information disclosure | personal-type IBAN leaking via slug | mitigate | PrivacyDefaultsTest asserts slug never contains IBAN substring; slug generation for personal type uses display name only |
| T-17-05a-02 | Information disclosure | cross-user data leak via resolver | mitigate | Every raw query in CounterpartyResolverService has explicit `->where('user_id', $userId)`; BelongsToUser is secondary; cross-user-isolation test (Test 9) covers regression |
| T-17-05a-03 | Tampering | self-account misclassification routing user's own legs through merchant resolution | mitigate | Step 1 of the chain (self-account check) runs FIRST and short-circuits — no Counterparty row is created for self_account, so any subsequent step misclassifying would have to bypass step 1 entirely (which the test set covers) |
</threat_model>

<verification>
After both tasks:

1. `vendor/bin/pest Modules/Counterparties/tests/Unit/` all green (14 tests)
2. `php artisan migrate:fresh` succeeds (counterparties table + transactions.counterparty_id column both materialize)
3. `composer test` (full quality gate) green
</verification>

<success_criteria>
- All 7 must_haves true
- 7-step resolution chain implemented with each step covered by a behavior test
- Personal-IBAN privacy default verified end-to-end via PrivacyDefaultsTest
- Slug collision suffixing per-user verified via SlugCollisionTest
- Module loads + boots cleanly
- NO ImportPipeline edits (deferred to 17-05b)
</success_criteria>

<output>
Create `.planning/phases/17-ci-cd-pipeline-code-signing/17-05a-SUMMARY.md` capturing: the final migration timestamps, the resolver's per-step branch-decision log, the Dutch-IBAN regex chosen, the government keyword set, and any deviations from PATTERNS.md analogs.
</output>
