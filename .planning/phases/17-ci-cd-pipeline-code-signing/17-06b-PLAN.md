---
phase: 17-ci-cd-pipeline-code-signing
plan: 06b
type: execute
wave: 3
depends_on:
  - 17-04a
  - 17-05b
  - 17-06a
files_modified:
  - Modules/Counterparties/Routes/web.php
  - Modules/Counterparties/Providers/CounterpartiesServiceProvider.php
  - Modules/Counterparties/Internal/Http/Livewire/CounterpartyIndex.php
  - Modules/Counterparties/Internal/Http/Livewire/CounterpartyProfile.php
  - Modules/Counterparties/Internal/Http/Livewire/CounterpartyTriage.php
  - Modules/Counterparties/Public/Queries/CounterpartyIndexQuery.php
  - Modules/Counterparties/Public/Queries/CounterpartyProfileQuery.php
  - Modules/Counterparties/Public/Queries/CounterpartyTriageQueue.php
  - Modules/Counterparties/Resources/views/livewire/counterparty-index.blade.php
  - Modules/Counterparties/Resources/views/livewire/counterparty-profile.blade.php
  - Modules/Counterparties/Resources/views/livewire/counterparty-triage.blade.php
  - Modules/Counterparties/Resources/views/livewire/profile-tabs/merchant.blade.php
  - Modules/Counterparties/Resources/views/livewire/profile-tabs/personal.blade.php
  - Modules/Counterparties/Resources/views/livewire/profile-tabs/bank.blade.php
  - Modules/Counterparties/Resources/views/livewire/profile-tabs/government.blade.php
  - Modules/Counterparties/Resources/views/livewire/profile-tabs/unknown.blade.php
  - Modules/Core/Database/Migrations/XXXX_add_counterparty_index_view_to_user_preferences.php
  - Modules/Core/Models/UserPreference.php
  - Modules/Counterparties/tests/Feature/CounterpartyIndexTest.php
  - Modules/Counterparties/tests/Feature/CounterpartyProfileTest.php
  - Modules/Counterparties/tests/Feature/CounterpartyTriageTest.php
autonomous: false
requirements:
  - gap-counterparty-module-ui-pages
requirements_addressed:
  - gap-counterparty-module-ui-pages
must_haves:
  truths:
    - "/counterparties index renders cards-default with the type-filter chip row (All / Merchants / Personal / Banks / Government / Self / Unknown)"
    - "/counterparties supports view toggle Cards/List persisted in user_preferences.counterparty_index_view (column added via additive migration that depends on 17-04a foundation)"
    - "/counterparties/{slug} renders the type-aware profile (5 type-specific bodies + unknown fallback + self_account stub)"
    - "Personal-type profile renders the privacy banner + IBAN hidden by default behind a Show IBAN toggle"
    - "Self-account-type profile renders the stub redirect, NOT tabs or hero stats"
    - "/counterparties/triage renders the focused single-card queue with Y/N/S/→/Esc keyboard handlers"
    - "Triage progress copy renders verbatim: '{seen} of {total} · {percent} % · ~{minutes} min remaining'"
    - "Personal IBANs never appear in lists, search results, URLs, or page titles"
    - "All copy is verbatim from 17-UI-SPEC.md (no paraphrasing)"
  artifacts:
    - path: "Modules/Counterparties/Routes/web.php"
      provides: "Three routes: /counterparties, /counterparties/triage, /counterparties/{slug} — triage BEFORE {slug} so literal matches first"
    - path: "Modules/Counterparties/Internal/Http/Livewire/CounterpartyIndex.php"
      provides: "Index Livewire component with filter chips + view toggle"
    - path: "Modules/Counterparties/Internal/Http/Livewire/CounterpartyProfile.php"
      provides: "Type-aware profile shell that branches into 5 sub-views per type"
    - path: "Modules/Counterparties/Internal/Http/Livewire/CounterpartyTriage.php"
      provides: "Focused queue with keyboard handlers (Y/N/S/→/Esc)"
    - path: "Modules/Counterparties/Public/Queries/CounterpartyIndexQuery.php"
      provides: "DI-friendly read query for the index (filter + sort + view)"
    - path: "Modules/Counterparties/Public/Queries/CounterpartyProfileQuery.php"
      provides: "Read query for a single counterparty profile + its activity"
    - path: "Modules/Counterparties/Public/Queries/CounterpartyTriageQueue.php"
      provides: "Read query for unknown counterparties + suggestion ranking"
    - path: "Modules/Core/Database/Migrations/XXXX_add_counterparty_index_view_to_user_preferences.php"
      provides: "Additive column add: counterparty_index_view VARCHAR(16) DEFAULT 'cards' (foundation table from 17-04a)"
  key_links:
    - from: "Plan 17-06b view toggle"
      to: "user_preferences.counterparty_index_view"
      via: "Direct query-builder update scoped to current user"
    - from: "Plan 17-06b CounterpartyTriageQueue.suggestionFor"
      to: "Modules/Counterparties/Public/Contracts/CounterpartyResolver (from 17-05a)"
      via: "DI consumption"
---

<objective>
Ship the Livewire UI for /counterparties, /counterparties/{slug}, and /counterparties/triage. Consumes the 8 x-components + CSS from 17-06a, the resolver from 17-05a/05b, and the user_preferences foundation from 17-04a.

Purpose: This is the page-level UI work. By isolating it from the x-component/CSS shell (17-06a) and from the cross-module click-through (17-06c), the executor has a tight, page-focused scope: three Livewire components + three Public read queries + verbatim-from-UI-SPEC views + Pest Feature tests covering all 23 user-visible behaviors.

**Important per CLAUDE.md skill auto-load:** Phase 17 counterparty surfaces auto-trigger `Skill("sketch-findings-diederik")`. The 17-UI-SPEC.md document is the authoritative source for every interaction and copy decision and supersedes the sketch findings.

Output: Three working routes wired to three Livewire components reading from three Public queries; all per-type profile partials render the right body; Triage page keyboard handlers respect the input carve-out; cross-user 404 + personal-IBAN privacy default both verified end-to-end.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/17-ci-cd-pipeline-code-signing/17-UI-SPEC.md
@.planning/phases/17-ci-cd-pipeline-code-signing/17-PATTERNS.md
@Modules/Counterparties/Public/Contracts/CounterpartyResolver.php
@Modules/Counterparties/Models/Counterparty.php
@Modules/Core/Models/UserPreference.php
@Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php
@Modules/Onboarding/Routes/web.php
@Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php
@Modules/Community/Resources/views/livewire/mystery-merchants-page.blade.php

<interfaces>
<!-- Routes (Modules/Counterparties/Routes/web.php) — triage BEFORE {slug} -->
Route::middleware(['web','auth'])->group(function () {
    Route::get('/counterparties', CounterpartyIndex::class)->name('counterparties.index');
    Route::get('/counterparties/triage', CounterpartyTriage::class)->name('counterparties.triage');
    Route::get('/counterparties/{slug}', CounterpartyProfile::class)->name('counterparties.profile');
});

<!-- Read queries (Modules/Counterparties/Public/Queries/) — DI-friendly, used by Livewire components + future cross-module consumers -->

namespace Modules\Counterparties\Public\Queries;

final readonly class CounterpartyIndexQuery {
    public function __construct(private DatabaseManager $db) {}
    public function forUser(User $user, string $typeFilter = 'all', string $sort = 'total_12m_desc'): Collection;
    public function countsByType(User $user): array;  // ['all'=>N,'merchant'=>N,...]
}

final readonly class CounterpartyProfileQuery {
    public function __construct(private DatabaseManager $db) {}
    public function bySlug(User $user, string $slug): ?CounterpartyProfile;  // null if not found OR cross-user
    public function recentActivity(Counterparty $cp, int $limit = 10): Collection;
    public function categoryBreakdown(Counterparty $cp): Collection;
    public function fundingChainSummary(Counterparty $cp): ?ChainSummary;  // null when type != merchant
    public function taxYearBreakdown(Counterparty $cp): Collection;        // government type only
}

final readonly class CounterpartyTriageQueue {
    public function __construct(private DatabaseManager $db, private CounterpartyResolver $resolver) {}
    public function forUser(User $user, ?int $queueFirstId = null): array;
    public function suggestionFor(Counterparty $unknown): ?TriageSuggestion;
    public function unknownCountForUser(User $user): int;  // consumed by sidebar Triage badge
}
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: counterparty_index_view migration + UserPreference $fillable extension + foundation test</name>
  <files>Modules/Core/Database/Migrations/XXXX_add_counterparty_index_view_to_user_preferences.php, Modules/Core/Models/UserPreference.php, Modules/Counterparties/tests/Feature/UserPreferencesCounterpartyViewTest.php (added during execution)</files>
  <read_first>
    - Modules/Core/Database/Migrations/XXXX_create_user_preferences_table.php (from Plan 17-04a — confirms the foundation table exists)
    - Modules/Core/Models/UserPreference.php (from Plan 17-04a — extend $fillable + casts() with the new column)
    - Modules/Categorization/Database/Migrations/2026_05_17_010003_create_categorization_rules_table.php (analog — additive-column migration pattern with container-DI Migrator)
  </read_first>
  <behavior>
    - Test 1: `php artisan migrate` cleanly applies the additive migration on top of the 17-04a foundation
    - Test 2: After migrate, the `user_preferences` table has a `counterparty_index_view` VARCHAR(16) column with default 'cards'
    - Test 3: UserPreference::query()->create(['user_id' => $user->id, 'counterparty_index_view' => 'list']) persists; subsequent fetch returns 'list'
    - Test 4: A newly-inserted row WITHOUT specifying counterparty_index_view defaults to 'cards'
  </behavior>
  <action>Step A — Migration: create `Modules/Core/Database/Migrations/<timestamp>_add_counterparty_index_view_to_user_preferences.php` ADDING a `counterparty_index_view` VARCHAR(16) column with DEFAULT 'cards' to the existing `user_preferences` table (created in Plan 17-04a). This migration is ADDITIVE — it does NOT create the table. If the table is missing, fail loud (the depends_on contract is broken). Use container-DI Migrator pattern.

    Step B — Model: extend `Modules/Core/Models/UserPreference.php` to include `counterparty_index_view` in `$fillable`. Add no cast (string column → string PHP value).

    Step C — Pest test: write `Modules/Counterparties/tests/Feature/UserPreferencesCounterpartyViewTest.php` covering tests 1-4 above. Use `RefreshDatabase`; assert column exists via `Schema::hasColumn('user_preferences', 'counterparty_index_view')` (DI: inject `Illuminate\Database\Schema\Builder` via the test's container resolution).

    DI-only throughout. No facade calls in production code. PHPDocs describe present-tense steady-state.</action>
  <verify>
    <automated>php artisan migrate --pretend && vendor/bin/pest Modules/Counterparties/tests/Feature/UserPreferencesCounterpartyViewTest.php --stop-on-failure</automated>
  </verify>
  <done>All 4 behavior tests pass; column added cleanly; UserPreference $fillable extended; Larastan + Pint green.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: 3 Livewire components + 3 Public read queries + routes wiring + per-type profile partials + 23 Feature tests</name>
  <files>Modules/Counterparties/Routes/web.php, Modules/Counterparties/Providers/CounterpartiesServiceProvider.php, Modules/Counterparties/Internal/Http/Livewire/CounterpartyIndex.php, Modules/Counterparties/Internal/Http/Livewire/CounterpartyProfile.php, Modules/Counterparties/Internal/Http/Livewire/CounterpartyTriage.php, Modules/Counterparties/Public/Queries/CounterpartyIndexQuery.php, Modules/Counterparties/Public/Queries/CounterpartyProfileQuery.php, Modules/Counterparties/Public/Queries/CounterpartyTriageQueue.php, Modules/Counterparties/Resources/views/livewire/counterparty-index.blade.php, Modules/Counterparties/Resources/views/livewire/counterparty-profile.blade.php, Modules/Counterparties/Resources/views/livewire/counterparty-triage.blade.php, Modules/Counterparties/Resources/views/livewire/profile-tabs/merchant.blade.php, Modules/Counterparties/Resources/views/livewire/profile-tabs/personal.blade.php, Modules/Counterparties/Resources/views/livewire/profile-tabs/bank.blade.php, Modules/Counterparties/Resources/views/livewire/profile-tabs/government.blade.php, Modules/Counterparties/Resources/views/livewire/profile-tabs/unknown.blade.php, Modules/Counterparties/tests/Feature/CounterpartyIndexTest.php, Modules/Counterparties/tests/Feature/CounterpartyProfileTest.php, Modules/Counterparties/tests/Feature/CounterpartyTriageTest.php</files>
  <read_first>
    - Modules/Onboarding/Routes/web.php (analog — Route::middleware(['web','auth'])->group(...) pattern; note ORDER — literal `/triage` BEFORE `{slug}`)
    - Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php (analog — method-parameter DI on render(); wire action signatures; final class shape)
    - Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php (analog — page-shell + variant body pattern for the profile component)
    - Modules/Community/Resources/views/livewire/mystery-merchants-page.blade.php + its companion .php (analog — triage-queue pattern)
    - Modules/Counterparties/Public/Contracts/CounterpartyResolver.php (from Plan 17-05a — Triage queue consumes the resolver for suggestion generation)
    - resources/views/layouts/app.blade.php (the input-carve-out for keyboard handlers is documented here; the triage keyboard handler must respect it)
    - Sketch findings skill `Skill("sketch-findings-diederik")` (auto-loaded per CLAUDE.md) for Phase 17 counterparty surfaces; UI-SPEC overrides any conflict
    - .planning/phases/17-ci-cd-pipeline-code-signing/17-UI-SPEC.md § "Interaction Contracts" (Y/N/S/→ key handling)
  </read_first>
  <behavior>
    `CounterpartyIndexTest.php` (Feature):
    - Test 1 (renders cards by default): GET /counterparties returns 200 + renders the page title `Counterparties` + the `▦ Cards` view toggle is active
    - Test 2 (filter chips work): clicking the `Merchants` chip filters the grid; URL gains `?type=merchant`; counts on chips update
    - Test 3 (view toggle persists): switching to `≡ List` writes `counterparty_index_view='list'` to user_preferences; reload sees list mode
    - Test 4 (empty state): a user with zero counterparties sees the verbatim empty heading `No counterparties yet`
    - Test 5 (self-row routes to account view): clicking a self_account row navigates to `/accounts/{slug}` (existing Accounts surface)
    - Test 6 (cross-user isolation): user A's index never shows user B's counterparties
    - Test 7 (unknown card CTA): clicking `❋ Label this counterparty` on an unknown card routes to `/counterparties/triage?queue_first={id}`

    `CounterpartyProfileTest.php` (Feature):
    - Test 8 (merchant profile renders): GET /counterparties/{merchant-slug} renders the merchant tab bar (Overview/Transactions/Chains/Aliases) + the hero name + 12-month total
    - Test 9 (personal profile shows privacy banner + IBAN hidden): GET /counterparties/{personal-slug} renders the verbatim privacy banner copy + IBAN as dotted display (NOT the literal IBAN); clicking Show IBAN reveals it
    - Test 10 (personal profile slug never contains IBAN): the slug in the URL is the kebab-case display name; PrivacyDefaultsTest from Plan 17-05a plus this Feature test confirm the privacy-rule end-to-end
    - Test 11 (bank profile renders fee-bar layout): bank-type body shows fee-bar rows + amber tint per UI-SPEC
    - Test 12 (government profile shows tax-year breakdown): government-type body shows the 3-up year cards with the current year emphasized
    - Test 13 (self_account renders stub): GET /counterparties/{self-slug} renders the stub redirect heading `This isn't really a counterparty` + the `Open {name} account view →` CTA; does NOT render the tab bar
    - Test 14 (unknown renders fallback): GET /counterparties/{unknown-slug} renders the unknown tab bar (Overview/Transactions/Aliases, no Chains) + the prominent `Label this counterparty` CTA
    - Test 15 (cross-user 404): a counterparty owned by user B returns 404 (not 403) when accessed by user A

    `CounterpartyTriageTest.php` (Feature):
    - Test 16 (renders progress copy verbatim): GET /counterparties/triage with 23 of 61 unknowns labeled renders `23 of 61 · 38 % · ~15 min remaining`
    - Test 17 (suggestion banner with reasoning): given an unknown that the resolver suggests as Netflix with high confidence + a reasoning string, the banner renders `✨ Looks like **Netflix** — confidence high` + the reasoning sub-line
    - Test 18 (Y key accepts): pressing Y (simulated via Livewire wire:keydown.window.y handler) calls `acceptSuggestion()` which links the unknown to the suggested counterparty + advances to next
    - Test 19 (N key rejects): pressing N dismisses the suggestion + focuses the manual-label section
    - Test 20 (S key skips): pressing S re-queues at end of session
    - Test 21 (→ key advances): pressing → advances to next unknown
    - Test 22 (input carve-out): pressing Y while focus is inside a manual-label INPUT does NOT trigger acceptSuggestion (the project's existing keyboard carve-out from layouts/app.blade.php applies)
    - Test 23 (empty queue): a user with zero unknowns sees the verbatim `🎉 All caught up — every counterparty is labeled.`
  </behavior>
  <action>Step A — Routes: fill in `Modules/Counterparties/Routes/web.php` per the interfaces block. Order matters: `/counterparties` → `/counterparties/triage` → `/counterparties/{slug}` (literal before placeholder so `/triage` matches `counterparties.triage` not `counterparties.profile`).

    Step B — Read queries: create the three classes under `Modules/Counterparties/Public/Queries/`. Each is `final readonly class` with `DatabaseManager` constructor DI per PATTERNS.md. Implement per the `<interfaces>` block:
    - `CounterpartyIndexQuery::forUser` returns a Collection of DTO rows containing: id, slug, display_name, type, total_12m (SQL aggregate from transactions joined on counterparty_id), avg_per_month, recent_line (most recent transaction's date + short description), sparkline (12 months × monthly total — array of 12 ints). All aggregates computed via SQL `GROUP BY` + `SUM`, never PHP-side loops.
    - `countsByType` returns per-type counts in one query.
    - `CounterpartyProfileQuery::bySlug` returns a profile DTO with the hero stats + tab data; null when the counterparty doesn't exist OR belongs to a different user (BelongsToUser-scoped).
    - `recentActivity`, `categoryBreakdown`, `fundingChainSummary`, `taxYearBreakdown` are method-level read helpers — funding chain returns null for non-merchant types; tax-year is government-only.
    - `CounterpartyTriageQueue::forUser` returns unknown counterparties ordered by recency + transaction count.
    - `suggestionFor` calls into `CounterpartyResolver` (DI'd) to generate a suggestion — confidence levels (high/medium/low) come from a simple heuristic: high if all transactions on the IBAN match a single merchant via the resolver, medium if 60-100% match, low otherwise; reasoning is the 1-sentence rationale per UI-SPEC.
    - `unknownCountForUser` returns an integer count for the sidebar Triage badge.

    Step C — Wire sidebar Triage badge count: update `Modules/Core/Resources/views/livewire/app-sidebar.blade.php` to DI `CounterpartyTriageQueue $triage` in the sidebar component's render() and replace 17-06a's placeholder `{{ $unknownCount ?? 0 }}` with `{{ $triage->unknownCountForUser($cu->user()) }}`.

    Step D — Livewire components: create the three components under `Modules/Counterparties/Internal/Http/Livewire/`. Each extends `Livewire\Component`. NO constructor DI (PATTERNS.md). Method-parameter DI on `render()` and every wire action.
    - `CounterpartyIndex`: public properties `$type='all'`, `$view='cards'`, `$search=''`. `render(CurrentUser $cu, CounterpartyIndexQuery $q, ViewFactory $views)` returns `$views->make('counterparties::livewire.counterparty-index', ['rows' => $q->forUser($cu->user(), $this->type), 'counts' => $q->countsByType($cu->user())])`. Wire actions: `setType(string $type)`, `setView(string $view)` (also writes to user_preferences.counterparty_index_view via injected DB), `clearSearch()`.
    - `CounterpartyProfile`: public property `$slug`, `$tab='overview'`. `mount(string $slug)` sets the slug. `render(CurrentUser $cu, CounterpartyProfileQuery $q, ViewFactory $views)` resolves the counterparty + branches the view into the right `profile-tabs/{type}.blade.php` partial. Wire actions: `switchTab(string $tab)`, `toggleIban()` (personal type — wire-side toggle so re-renders re-hide).
    - `CounterpartyTriage`: public properties `$currentIndex=0`, `$showSuggestion=true`, `$queueFirstId=null`. `mount(?int $queue_first=null)` accepts the query param. `render(CurrentUser $cu, CounterpartyTriageQueue $q, ViewFactory $views)` builds the queue + computes the suggestion for the current item. Wire actions: `acceptSuggestion()`, `rejectSuggestion()`, `skipForNow()`, `markIgnored()`, `nextItem()`, `previousItem()`, `manualLabel(string $name, string $type)`.

    Step E — Blade views: create the three top-level Livewire views + the 5 profile-tab partials under `profile-tabs/`. Each view assembles the x-components from Plan 17-06a to render the surface per UI-SPEC. All copy verbatim from UI-SPEC. Tailwind classes literal. Escape via `{{ }}`. For the triage page, attach the keyboard handler at the root element: `wire:keydown.window.y="acceptSuggestion"` (etc.) BUT only when `$showSuggestion` is true and `$type === 'unknown'` (use `@if` guards). Document the input-carve-out by NOT attaching `.window` modifier when focus is inside the manual-label fieldset (use Alpine `x-data="{ inputFocused: false }"` + `@focusin.capture` + `@focusout.capture` to track focus state and gate the handler).

    Step F — Register components in CounterpartiesServiceProvider: in `boot()` (via injected `Livewire\LivewireManager $livewire`), add `$livewire->component('counterparties.index', CounterpartyIndex::class); $livewire->component('counterparties.profile', CounterpartyProfile::class); $livewire->component('counterparties.triage', CounterpartyTriage::class);`.

    Step G — Pest tests: write the three Feature test files covering tests 1-23. Use `Livewire::test(...)` for component-level assertions and `$this->get(...)` for route-level. `RefreshDatabase` + beforeEach user + fixture counterparties seeded directly. All copy assertions use `assertSeeText(...)` against the VERBATIM UI-SPEC strings.</action>
  <verify>
    <automated>vendor/bin/pest Modules/Counterparties/tests/Feature/CounterpartyIndexTest.php Modules/Counterparties/tests/Feature/CounterpartyProfileTest.php Modules/Counterparties/tests/Feature/CounterpartyTriageTest.php --stop-on-failure && php artisan route:list | grep -q "counterparties.index" && php artisan route:list | grep -q "counterparties.triage" && php artisan route:list | grep -q "counterparties.profile"</automated>
  </verify>
  <done>All 23 behavior tests pass; three routes registered; component registration verified via `php artisan livewire:list` (or `route:list`); sidebar Triage count wired to real query; cross-user isolation tests (Test 6, Test 15) pass; personal-IBAN privacy default (Test 9, Test 10) verified end-to-end.</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <what-built>Counterparties UI pages shipped: index + 5 type-aware profile bodies + triage with keyboard shortcuts (Tasks 1-2)</what-built>
  <how-to-verify>
    Visual + interaction verification on a real install with realistic data. Pre-condition: at least one prior import exists so counterparties of multiple types are present.

    1. **Sidebar:** Confirm `Counterparties` appears between Chains and Recurring in the Money section; confirm `Triage` carries an amber count badge if you have unknown counterparties.
    2. **Index page (`/counterparties`):** cards-default view; seven filter chips with correct counts; click filters update URL; view toggle to List persists across reload; `/` focuses search; empty-state verbatim copy; click unknown card CTA routes to triage with queue_first param; self_account row routes to /accounts/{slug}
    3. **Profile pages — all 6 type variants** (merchant/personal/bank/government/self_account/unknown) render per UI-SPEC; personal IBAN starts hidden + Show IBAN reveals + navigate-away-and-back re-hides
    4. **Triage page (`/counterparties/triage`):** progress copy verbatim; high-confidence suggestion banner + reasoning sub-line; Y/N/S/→/Esc handlers per UI-SPEC; input carve-out (Y inside manual-label INPUT does NOT trigger acceptSuggestion); empty queue verbatim 🎉 copy
    5. **Cross-user safety:** as user A, GET `/counterparties/{user-b-slug}` returns 404
    6. **Personal IBAN privacy:** IBAN NEVER in URL/title/card text/search results; only inside profile body behind Show IBAN
    7. **Accessibility:** axe/Lighthouse a11y pass; aria-labels present; tab focus order logical; focus rings visible

    Reply with `approved` + screenshots of: index with chips + active filter, all 6 profile types, triage with suggestion banner, sidebar with badges.
  </how-to-verify>
  <resume-signal>Type `approved` with screenshots, OR describe failures.</resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser → CounterpartyProfile component | URL slug is a user-controlled string; cross-user 404 prevents enumeration attacks |
| Livewire snapshot → wire (browser) | Public properties on the three components are serialized into the browser — must not reference any SecretsColumnRegistry entry (Plan 17-08 lands the arch invariant) |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-06b-01 | Information disclosure | personal IBAN leaking in URL / page title / breadcrumb | mitigate | Slug for personal type is display-name kebab; PrivacyDefaultsTest from Plan 17-05a + Test 10 here cover the full path; aria-label and page <title> use display name only |
| T-17-06b-02 | Information disclosure | cross-user counterparty enumeration via slug guessing | mitigate | bySlug returns null for cross-user; route resolver returns 404 (not 403 — no signal that the slug exists); Test 15 covers |
| T-17-06b-03 | Tampering | view-mode preference cross-user write | mitigate | setView wire action uses the injected CurrentUser to scope the user_preferences write; never trusts request input for user_id |
| T-17-06b-04 | Information disclosure | Livewire snapshot leaking secrets via public props | mitigate | None of the three components declare oauth_secrets-related props; noSecretsInLivewireSnapshot arch invariant (Plan 17-08) locks this at the test-suite level |
| T-17-06b-05 | Denial of service | massive transactions table making profile page slow | accept | UI-SPEC dropped the explicit 200ms budget per A-04; planner mitigates via SQL aggregates + indexes on (user_id, counterparty_id); profile uses eager-loaded paginated queries |
</threat_model>

<verification>
After all tasks + checkpoint:

1. `vendor/bin/pest Modules/Counterparties/tests/Feature/` all green (23 + 4 = 27 behaviors)
2. Three routes registered + reachable
3. user_preferences.counterparty_index_view column persists view choice
4. Cross-module click-through wiring is NOT in this plan (deferred to 17-06c)
5. `composer test` green
</verification>

<success_criteria>
- All 9 must_haves true
- All copy verbatim from 17-UI-SPEC.md
- Personal-IBAN privacy default holds across URLs, slugs, titles, lists
- Triage keyboard handlers respect input carve-out
- Self-account routes to /accounts (no double-render)
</success_criteria>

<output>
Create `.planning/phases/17-ci-cd-pipeline-code-signing/17-06b-SUMMARY.md` capturing: the three Livewire component file paths, the 5 profile-tab partial paths, the verbatim Triage progress formula chosen, the migration timestamp for counterparty_index_view, and screenshots referenced in the checkpoint.
</output>
