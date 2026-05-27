---
phase: 17-ci-cd-pipeline-code-signing
plan: 06c
type: execute
wave: 4
depends_on:
  - 17-06b
files_modified:
  - Modules/Ledger/Resources/views/* (transaction-row partials — discovered during read_first)
  - Modules/Recurring/Resources/views/* (transaction-row partials)
  - Modules/Chains/Resources/views/* (transaction-row partials)
  - Modules/Categorization/Resources/views/* (transaction-row partials)
  - app/Models/Transaction.php OR Modules/Ledger/Models/Transaction.php (discovered during read_first)
autonomous: false
requirements:
  - gap-counterparty-click-through
requirements_addressed:
  - gap-counterparty-click-through
must_haves:
  truths:
    - "Every transaction-row template that renders a counterparty name is now wrapped in a route('counterparties.profile', ...) anchor"
    - "self_account counterparties route to the existing /accounts/{slug} surface (not /counterparties/{slug})"
    - "Transaction model has a counterparty() BelongsTo relation pointing at Modules\\Counterparties\\Models\\Counterparty"
    - "Eager-loading prevents N+1 query regression (verified via Telescope / DB::listen in a real index render)"
    - "Existing Feature tests in Ledger / Recurring / Chains / Categorization still pass after the click-through wiring"
  artifacts:
    - path: "(transaction-row Blade partials in Ledger/Recurring/Chains/Categorization)"
      provides: "Wrapped counterparty-name display in a clickable anchor"
    - path: "(Transaction model)"
      provides: "BelongsTo counterparty() relation"
  key_links:
    - from: "transaction-row counterparty name (any module — Ledger, Recurring, Chains, Categorization)"
      to: "/counterparties/{slug} (or /accounts/{slug} for self_account)"
      via: "click-through link wrapping the counterparty name"
      pattern: "counterparties.profile"
---

<objective>
Wire every transaction-row counterparty name across Ledger / Recurring / Chains / Categorization to the Counterparty profile (or Account view for self_account).

Purpose: D-46 — clicking a counterparty name on any transaction row navigates to its profile page. This is the last cross-cutting wire-up step that turns the Counterparties surfaces (17-06a + 17-06b) from a standalone destination into a navigable backbone reachable from anywhere the user sees a counterparty.

Splitting from 17-06b: this work touches 4 unrelated modules and changes their view markup; bundling with the Counterparty Livewire pages would force the executor to context-switch between Counterparty-module concerns and cross-module template surgery within the same plan, increasing context cost and review burden.

Output: Every transaction-row counterparty name across the app is a clickable link routing to the right profile (self_account routes to /accounts); no N+1 query regression; all existing module tests still pass.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/17-ci-cd-pipeline-code-signing/17-UI-SPEC.md
@.planning/phases/17-ci-cd-pipeline-code-signing/17-PATTERNS.md
@Modules/Counterparties/Models/Counterparty.php
</context>

<tasks>

<task type="auto">
  <name>Task 1: Transaction model BelongsTo counterparty() relation + eager-loading hookup</name>
  <files>(Transaction model file — discovered during read_first; likely app/Models/Transaction.php OR Modules/Ledger/Models/Transaction.php)</files>
  <read_first>
    - `grep -rln "class Transaction extends Model" Modules/ app/ 2>/dev/null` to locate the Transaction model
    - The model file itself — confirm namespace + existing relations
    - Modules/Counterparties/Models/Counterparty.php (the FK target — confirm namespace + table name)
  </read_first>
  <action>Add a `counterparty()` BelongsTo relation on the Transaction model: `public function counterparty(): BelongsTo { return $this->belongsTo(\Modules\Counterparties\Models\Counterparty::class); }`. The FK column is `counterparty_id` (added by Plan 17-05a's `XXXX_add_counterparty_id_to_transactions.php` migration). Add the relation to the model's `$with` or document where consumers should eager-load (`->with('counterparty')`) to avoid N+1 in list-render paths.

    Also: discover whether the existing Transaction model declares a `$casts` or `relationships()` accessor convention; mirror it. PHPDoc on the relation method describes WHAT the relation is in present tense.</action>
  <verify>
    <automated>grep -q "public function counterparty(): BelongsTo" $(find Modules app -name "Transaction.php" -path "*/Models/*" 2>/dev/null | head -1) && grep -q "Modules\\\\Counterparties\\\\Models\\\\Counterparty" $(find Modules app -name "Transaction.php" -path "*/Models/*" 2>/dev/null | head -1)</automated>
  </verify>
  <done>Transaction model declares counterparty() BelongsTo; namespace + FK column correct; Larastan + Pint green; existing Transaction tests still pass.</done>
</task>

<task type="auto">
  <name>Task 2: Wrap counterparty names in transaction-row partials across Ledger / Recurring / Chains / Categorization</name>
  <files>(transaction-row Blade partials — discovered during read_first; ALL files that render a counterparty name on a transaction row across Modules/Ledger/Resources/views, Modules/Recurring/Resources/views, Modules/Chains/Resources/views, Modules/Categorization/Resources/views)</files>
  <read_first>
    - `grep -rln "counterparty" Modules/Ledger/Resources/views Modules/Recurring/Resources/views Modules/Chains/Resources/views Modules/Categorization/Resources/views 2>/dev/null` to find every Blade file that renders a counterparty name on a transaction row
    - For each found file, read it and confirm the current rendering pattern (plain text? button? existing link?)
    - .planning/phases/17-ci-cd-pipeline-code-signing/17-UI-SPEC.md § "Index page interactions → Click any other card / row" + § "Transaction-row click-through" (locked decision D-46)
    - `grep -rln "accounts.show\\|accounts.index" Modules/Ledger/Routes Modules/Accounts/Routes 2>/dev/null` to discover the Account route name (or wherever the existing Accounts surface lives)
  </read_first>
  <action>For each transaction-row template discovered in `read_first`, wrap the counterparty-name display in a link/anchor:

    ```blade
    @if ($transaction->counterparty_id && $transaction->counterparty)
        @if ($transaction->counterparty->type === 'self_account')
            <a href="{{ route('accounts.show', $transaction->counterparty->slug) }}"
               class="hover:underline focus-visible:underline focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:rounded">
                {{ $transaction->counterparty->display_name }}
            </a>
        @else
            <a href="{{ route('counterparties.profile', $transaction->counterparty->slug) }}"
               class="hover:underline focus-visible:underline focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:rounded">
                {{ $transaction->counterparty->display_name }}
            </a>
        @endif
    @else
        {{ $transaction->counterparty_name ?? '—' }}
    @endif
    ```

    Replace the actual route name `accounts.show` with whatever was discovered during read_first (it might be `accounts.index`, `account.show`, etc.). The fall-back plain-text display covers unknown rows that haven't been resolved yet (counterparty_id NULL).

    Defensive: only render the link when `$transaction->counterparty_id` is non-null AND the counterparty is loaded; fall back to plain text (the raw `counterparty_name` column or `—`) when counterparty_id is null.

    Eager-loading: discover the query that produces each row collection (likely in a Livewire component's render() or a Query service); add `->with('counterparty')` to prevent N+1. If no eager-loading exists, add it.

    Tests: each touched module already has Feature tests asserting transaction-list pages render. Add a single assertion to ONE representative test per module (Ledger, Recurring, Chains, Categorization) that confirms the counterparty name is now rendered as an anchor pointing at the right route. Example:
    ```php
    $response = $this->get('/ledger/transactions');
    $response->assertSee('href="' . route('counterparties.profile', $netflixCounterparty->slug) . '"', false);
    ```

    No new copy. No UI restyling beyond wrapping in `<a>` (the existing visual treatment stays). NO GSD vocabulary in any added markup.</action>
  <verify>
    <automated>grep -rln "route('counterparties.profile'" Modules/Ledger/Resources/views Modules/Recurring/Resources/views Modules/Chains/Resources/views Modules/Categorization/Resources/views 2>/dev/null | wc -l | xargs test 0 -lt && vendor/bin/pest Modules/Ledger/tests/ Modules/Recurring/tests/ Modules/Chains/tests/ Modules/Categorization/tests/ --stop-on-failure</automated>
  </verify>
  <done>Every transaction-row template that renders a counterparty name is wrapped in an anchor (self_account → accounts route; everything else → counterparties.profile); eager-loading prevents N+1; all existing module Feature tests still pass; one representative test per touched module asserts the anchor is present; Larastan + Pint green.</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <what-built>Cross-module click-through wiring across Ledger / Recurring / Chains / Categorization</what-built>
  <how-to-verify>
    1. Open Ledger transactions list — every counterparty name is a clickable link routing to its profile (self_account routes to the account)
    2. Repeat for Recurring + Chains + Categorization
    3. Hover state: underline appears; focus state: visible focus ring
    4. No N+1: run with Telescope or `DB::listen` in dev mode; counterparty rendering should add ~1 query for the whole list, not 1-per-row
    5. Click a counterparty name from a Ledger row → lands on /counterparties/{slug} with the right profile rendered
    6. Click a self_account counterparty name from any row → lands on /accounts/{slug} (existing Accounts surface)
    7. Reply with `approved` + screenshots showing the click-through working from each of the 4 modules, OR describe what failed.
  </how-to-verify>
  <resume-signal>Type `approved` with click-through screenshots from each module, OR describe failures.</resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| transaction-row link → /counterparties/{slug} | Anchor href is route()-generated; slug comes from the loaded relation; cannot be tampered to point at another user's counterparty (BelongsToUser scope blocks load) |
| eager-loaded counterparty relation → cross-user leak | Eloquent's BelongsTo respects model global scopes; BelongsToUser on Counterparty prevents loading another user's counterparty |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-06c-01 | Information disclosure | a transaction row from user A inadvertently rendering user B's counterparty name via eager-load | mitigate | BelongsToUser scope on Counterparty model + BelongsToUser on Transaction model; tests assert cross-user isolation at both load sites |
| T-17-06c-02 | Denial of service | N+1 query regression on transaction lists | mitigate | Eager-loading via `->with('counterparty')` added during Task 2; verified manually in human-verify step 4 |
</threat_model>

<verification>
After both tasks + checkpoint:

1. All cross-module Feature tests STILL pass
2. Manual N+1 check via DB::listen confirms 1 added query per list (not 1-per-row)
3. Click-through works in all 4 modules
4. self_account routes to /accounts (no double-render)
5. `composer test` green
</verification>

<success_criteria>
- All 5 must_haves true
- Counterparty names clickable across Ledger / Recurring / Chains / Categorization
- self_account correctly routes to Accounts surface
- No N+1 regression
- Zero regressions in existing module tests
</success_criteria>

<output>
Create `.planning/phases/17-ci-cd-pipeline-code-signing/17-06c-SUMMARY.md` capturing: the full list of transaction-row templates modified, the eager-loading hookup line numbers, the Account route name discovered, and any modules where the click-through wire-up required an unexpected accommodation.
</output>
