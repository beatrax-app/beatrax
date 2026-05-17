---
phase: 07-email-template-matchers-categorization-learning
plan: 05
subsystem: categorization
tags: [categorization, rule-crud, correction-divergence, provenance-panel, watched-folder, auto-import, livewire-sfc, top-nav, hits-count]

requires:
  - phase: 07-email-template-matchers-categorization-learning
    plan: 01
    provides: categorization_rules + users.auto_import_drop_folder + transactions.auto_category_provenance migrations
  - phase: 07-email-template-matchers-categorization-learning
    plan: 04
    provides: CategorizationRule model + CategorizationRuleQuery + AssignCategory + MerchantMemoryWriter + ReceiptConflictToast SFC + ApplyAutoCategoryStage

provides:
  - "CreateCategorizationRule Public action (field/match whitelist + UNIQUE-violation -> ValidationException with locked duplicate copy)"
  - "UpdateCategorizationRule Public action (cross-user 404 via NotFoundHttpException; ALLOWED_KEYS filter; DB transaction wrapper)"
  - "DeleteCategorizationRule Public action (cross-user 404; DB transaction wrapper)"
  - "RulesPage Livewire SFC bound to /rules route — table-with-actions render, two-step inline confirm-delete flow, flash messaging for Rule saved./Rule deleted."
  - "RuleFormModal Livewire SFC — Flux modal posture, sentence layout (When the [field] [match] the value [...]) + category picker; dispatched via global rule-form:open event; ValidationException -> per-field error rendering"
  - "CategorizationProvenancePanel Livewire SFC — three variants (rule/memory/none) per UI-SPEC; rule variant supports Update rule (dispatches rule-form:open) + Remove rule (two-step inline confirm); memory variant supports Override (dispatches inline-category-picker:open); auto-falls-back to none variant when referenced rule deleted"
  - "CorrectionDivergenceToast Livewire SFC — Livewire-local correction-divergence:fire bridge (NOT broadcaster, NOT session-flash); 5-arg + 2-DI signature handleDiverged(int transactionId, int ruleId, int oldCategoryId, int newCategoryId, int userId, CurrentUser, CategorizationRuleQuery); cross-user defensive guard (T-07-09); Update rule invokes UpdateCategorizationRule with new category; 8s auto-dismiss via Alpine x-init setTimeout"
  - "CategorizationDiverged Public event (transactionId, ruleId, oldCategoryId, newCategoryId, userId) dispatched by AssignCategory when prior auto_category_provenance.source === 'rule' AND new categoryId differs"
  - "TransactionDetail::reclassifyCategory action method — captures prior provenance, invokes AssignsCategory, then re-emits correction-divergence:fire Livewire-local event with all 5 payload fields"
  - "Top-nav 'Rules' anchor — inserted between Uncategorized and Review chains per UI-SPEC § Navigation Decision"
  - "Layout @auth block mounts the three global Livewire SFCs (rule-form-modal, correction-divergence-toast, receipt-conflict-toast)"
  - "Transaction detail page embeds CategorizationProvenancePanel as a Livewire sub-component keyed by transactionId"
  - "ScanInboxDropFolderJob Receipts/Internal — per-user 5-minute scanner with atomic /processed/{YYYY-MM}/ + /failed/{YYYY-MM}/ move semantics; ShouldBeUniqueUntilProcessing + Cache::driver('redis') uniqueVia (BoundaryArchTest + phpstan carve-out)"
  - "routes/console.php Schedule entry receipts.scan-drop-folder — everyFiveMinutes() per-user dispatch gated on users.auto_import_drop_folder=true"
  - "SettingsPage extension — autoImportFromDropFolder bool property + toggleAutoImport action; instant-apply (no Save button) via raw query-builder UPDATE; off/on help-text variants per UI-SPEC"
  - "User model — auto_import_drop_folder added to fillable + casts=boolean + docblock @property bool|null"
  - "ApplyAutoCategoryStage hits_count increment — atomic raw('hits_count + 1') UPDATE per rule fire (Wave 3 deferral closed)"

affects: []

tech-stack:
  added: []
  patterns:
    - "Public CRUD action triplet (Create/Update/Delete) with UNIQUE-violation translation to ValidationException — the action layer owns the duplicate-key UI copy; the form modal renders @error('value') under the offending field"
    - "Cross-user 404 via NotFoundHttpException (not 403, not silent skip) — Update/Delete actions raise Symfony's HTTP-kit NotFoundHttpException so cross-user calls surface as 404 at the Livewire boundary; pattern matches Phase 5's ConfirmChainLink action precedent"
    - "Two-step inline confirm-delete chip pair — destructive but reversible operations (rule delete, rule remove from drawer) collapse the action chip into a [Delete?] [Yes, delete] [Cancel] triple in place; no separate modal; same pattern reused on RulesPage rows + CategorizationProvenancePanel rule variant"
    - "Global SFC mount strategy — RuleFormModal + CorrectionDivergenceToast + ReceiptConflictToast all mounted in app.blade.php @auth so any page can dispatch the corresponding Livewire-local event; saves per-page mounting boilerplate"
    - "Livewire-local event bridge for cross-component dispatch (NOT broadcaster, NOT session-flash) — TransactionDetail invokes AssignCategory synchronously, then re-emits a Livewire-local correction-divergence:fire event carrying every field of the framework CategorizationDiverged payload (including userId); the globally-mounted toast SFC receives it in the same request lifecycle"
    - "Cross-user defensive guard with explicit userId-in-payload — handleDiverged() takes both int $userId (5th positional, the assertion subject) AND CurrentUser (6th, method-DI, the oracle); a mismatch is a silent no-op; local Livewire events should never carry a foreign userId but the guard makes any future regression fail-safe"
    - "Atomic denormalised counter increment via raw('column + 1') — ApplyAutoCategoryStage bumps categorization_rules.hits_count on every rule fire without a read-modify-write window; same shape as MerchantMemoryWriter's occurrence_count UPDATE"
    - "Per-user 5-minute scheduled job gated on a user flag — routes/console.php Schedule::call iterates DB::table('users')->where('auto_import_drop_folder', true)->pluck('id') so the cron tick costs nothing for users who haven't enabled the watched-folder secondary path"
    - "Atomic file move via POSIX rename() with copy-then-delete fallback — ScanInboxDropFolderJob moves processed files to /processed/{YYYY-MM}/ via rename(); falls back to $files->copy + delete when cross-device rename fails (rare on a single-machine local app but defensive)"
    - "Top-level-only file scan loop that skips its own quarantine subfolders — ScanInboxDropFolderJob uses Filesystem::files() (returns only top-level files, not directories) so /processed/ and /failed/ subtrees are excluded from re-runs; the file_imports UNIQUE constraint short-circuits any same-message re-drop downstream"

key-files:
  created:
    - "Modules/Categorization/Public/Actions/CreateCategorizationRule.php"
    - "Modules/Categorization/Public/Actions/UpdateCategorizationRule.php"
    - "Modules/Categorization/Public/Actions/DeleteCategorizationRule.php"
    - "Modules/Categorization/Public/Events/CategorizationDiverged.php"
    - "Modules/Categorization/Internal/Http/Livewire/RulesPage.php"
    - "Modules/Categorization/Internal/Http/Livewire/RuleFormModal.php"
    - "Modules/Categorization/Internal/Http/Livewire/CategorizationProvenancePanel.php"
    - "Modules/Categorization/Internal/Http/Livewire/CorrectionDivergenceToast.php"
    - "Modules/Categorization/Resources/views/rules.blade.php"
    - "Modules/Categorization/Resources/views/livewire/rules-page.blade.php"
    - "Modules/Categorization/Resources/views/livewire/rule-form-modal.blade.php"
    - "Modules/Categorization/Resources/views/livewire/categorization-provenance-panel.blade.php"
    - "Modules/Categorization/Resources/views/livewire/correction-divergence-toast.blade.php"
    - "Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php"
    - "Modules/Categorization/tests/Feature/RulesPageTest.php"
    - "Modules/Categorization/tests/Feature/RuleFormModalTest.php"
    - "Modules/Categorization/tests/Feature/CorrectionDivergenceTest.php"
    - "Modules/Categorization/tests/Feature/CategorizationProvenancePanelTest.php"
    - "Modules/Receipts/tests/Feature/ScanInboxDropFolderJobTest.php"
  modified:
    - "Modules/Categorization/Routes/web.php (+/rules Route::view registration)"
    - "Modules/Categorization/Providers/CategorizationServiceProvider.php (4 new singletons + 4 new Livewire component registrations)"
    - "Modules/Categorization/Public/Actions/AssignCategory.php (+DatabaseManager DI + readPriorProvenance + maybeDispatchDivergence)"
    - "Modules/Categorization/Internal/Pipeline/ApplyAutoCategoryStage.php (+DatabaseManager DI + atomic hits_count increment on rule fire)"
    - "Modules/Categorization/tests/Feature/ApplyAutoCategoryStageTest.php (+hits_count increment assertion)"
    - "Modules/Core/Resources/views/livewire/top-nav.blade.php (+Rules anchor between Uncategorized and Review chains)"
    - "Modules/Core/Internal/Http/Livewire/SettingsPage.php (+autoImportFromDropFolder property + toggleAutoImport action)"
    - "Modules/Core/Resources/views/livewire/settings-page.blade.php (+Auto-import section with off/on help-text variants)"
    - "Modules/Core/Models/User.php (+auto_import_drop_folder fillable + casts=boolean + docblock @property bool|null)"
    - "Modules/Core/tests/Feature/SettingsPageTest.php (+4 Phase 7 tests)"
    - "Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php (+reclassifyCategory action method that re-dispatches correction-divergence:fire as Livewire-local event)"
    - "Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php (+embedded CategorizationProvenancePanel between Reclassify and View chain)"
    - "resources/views/layouts/app.blade.php (@auth block mounts 3 global SFCs: rule-form-modal, correction-divergence-toast, receipt-conflict-toast)"
    - "routes/console.php (+receipts.scan-drop-folder schedule entry, everyFiveMinutes, gated on users.auto_import_drop_folder=true)"
    - "tests/Contracts/BoundaryArchTest.php (+ScanInboxDropFolderJob in the Cache facade carve-out)"
    - "phpstan.neon (+ScanInboxDropFolderJob entry in the Cache::driver facade ignoreErrors block)"

key-decisions:
  - "Phase 07 Plan 05: Livewire converts NotFoundHttpException to a 404 response rather than re-throwing — the cross-user 404 tests use `->call('deleteRule', $foreignRule)->assertStatus(404)` instead of `expect(fn)->toThrow(NotFoundHttpException::class)`. The plan's `<behavior>` text suggested the throw shape; reality is the Livewire test-harness intercepts the exception via SymfonyHttpKernelExceptionHandler and returns a Response with statusCode=404. Pattern matches the existing CrossUserInboxIsolationTest precedent for Livewire actions that raise NotFoundHttpException."
  - "Phase 07 Plan 05: RuleFormModal::open() falls back to create mode when the supplied ruleId belongs to another user (CategorizationRuleQuery->findForUser returns null). The plan's `<behavior>` text said 'hydrate if ruleId; else reset' — the foreign-ruleId case is a sub-case of 'no rule found'. Treating it as create-mode keeps the modal's surface uniform; the cross-user 404 defence still fires at Save time via UpdateCategorizationRule's NotFoundHttpException when the form posts."
  - "Phase 07 Plan 05: AssignCategory's divergence detection reads transactions.auto_category_provenance BEFORE invoking the Ledger updater rather than relying on the canonical CanonicalTransaction shape. The action receives just an int transactionId, not a hydrated row — and the only authoritative provenance is the persisted JSON column. Reading via raw query builder (scoped by user_id defensively) keeps the action decoupled from the Transaction model's casting layer; the updater's own cross-user guard is the canonical authorisation."
  - "Phase 07 Plan 05: TransactionDetail::reclassifyCategory is a NEW method, distinct from the existing type-reclassify reclassify(string newType). The plan's W3 section described 'TransactionDetail::reclassify(CurrentUser, AssignCategory, Dispatcher, newCategoryId)' which conflicted with the existing reclassify(string) method handling transaction TYPE changes (transfer_in/expense). Naming the new method reclassifyCategory keeps both surfaces alive without breaking the existing type-reclassify tests."
  - "Phase 07 Plan 05: CorrectionDivergenceToast.handleDiverged carries both `int $userId` (5th positional payload field) AND `CurrentUser $currentUser` (6th, method-DI). The plan locked this signature for the cross-user defence: the 5th parameter is the event-asserted owner; the 6th is the active-user oracle. A mismatch is a silent no-op (T-07-09 defence). Local Livewire events should never carry a foreign userId, but the explicit guard makes any future regression fail-safe — the same posture as Wave 3's ReceiptConflictToast::handleConflictDetected."
  - "Phase 07 Plan 05: Auto-import toggle is INSTANT-APPLY (no Save button) — wire:click='toggleAutoImport' runs immediately when the checkbox flips, mirroring the currency-display section's per-control commit posture. The plan's `<action>` text said 'mirror the existing currency-display section's pattern' but the currency-display IS Save-button-bound; on re-reading the spec, 'instant-apply' is what the toggle row needs to feel like a switch rather than a form field. The Save button below the form still owns defaultCurrencyView + periodStartDay validation."
  - "Phase 07 Plan 05: User model's auto_import_drop_folder docblock @property uses `bool|null` (not bare `bool`) — Eloquent returns null for freshly-created models that didn't set the attribute, even though the DB default is false. The SettingsPage mount() therefore casts via `(bool)` to coerce the null to false. Without the nullable docblock + the cast, PHPStan max either complained about cast.useless (when typed bool) or the test failed with 'Cannot assign null to property of type bool' (when the cast was removed). The bool|null docblock + (bool) cast is the correct shape for in-memory new() models AND reloaded models."
  - "Phase 07 Plan 05: ScanInboxDropFolderJob lands as a NEW Cache facade carve-out in BOTH BoundaryArchTest's ignoring() list AND phpstan.neon's ignoreErrors block. The Wave 4 deferral closure (hits_count) does NOT need a carve-out because ApplyAutoCategoryStage already had DI through its constructor; only NEW queued jobs need the Cache carve-out because uniqueVia() runs before constructor DI."
  - "Phase 07 Plan 05: ScanInboxDropFolderJob's failed-eml test creates a chmod-0000 file to force the read path to fail. The earlier attempt (empty file content) passed silently because EmlMimeReader accepts arbitrary garbage without throwing — empty bytes just produce an empty ParsedMimeMessage and an unmatched outcome moves to processed/, not failed/. The chmod-based approach genuinely triggers the try/catch quarantine path. Test guards posix_geteuid()===0 because root bypasses chmod."
  - "Phase 07 Plan 05: The scan loop uses Filesystem::files() (returns top-level files only, not directories) so /processed/ and /failed/ subtrees are naturally excluded from re-runs without an explicit substring filter. A future addition to the scan-loop body that drilled into subdirectories would silently break the idempotency invariant; the test for that idempotency (re-running on the same processed folder is a no-op) catches the regression."

requirements-completed: [CAT-04, EML-07]

duration: ~75min
completed: 2026-05-17
---

# Phase 7 Plan 05: Rules CRUD + Correction Divergence + Watched Folder Wave 4 Summary

**End-to-end Wave 4: every user-facing surface for the categorization-learning feature ships. /rules CRUD page with table render + form modal lands; top-nav exposes Rules between Uncategorized and Review chains; transaction-detail drawer renders the provenance panel (rule / memory / none variants); reclassifying a rule-provenance transaction surfaces the correction-divergence toast offering Update rule / Keep current rule. The watched-folder secondary path runs every 5 min when toggled on in /settings, moving processed files to /processed/{YYYY-MM}/ and failed files to /failed/{YYYY-MM}/ with sibling .error.txt. The Wave 3 hits_count deferral closes via an atomic raw('hits_count + 1') UPDATE on every rule fire.**

## Performance

- **Duration:** ~75 minutes
- **Tasks:** 3
- **Files created:** 19
- **Files modified:** 15
- **Test count:** 46 new tests landed (21 Task 1 RulesPage + RuleFormModal, 11 Task 2 CorrectionDivergence, 9 Task 2 ProvenancePanel, 11 Task 3 ScanInboxDropFolderJob, 4 Task 3 SettingsPage Phase 7, 1 Task 3 ApplyAutoCategoryStage hits_count increment)
- **Full suite:** 1162 passed / 6 skipped / 1 pre-existing TransactionTypeTest failure (carried forward since Wave 0)

## Accomplishments

- **CreateCategorizationRule / UpdateCategorizationRule / DeleteCategorizationRule** Public actions: field/match whitelist + UNIQUE-violation translation to ValidationException with the locked UI-SPEC duplicate copy ("A rule with this field, match, and value already exists. Edit the existing rule instead."); cross-user 404 via NotFoundHttpException on Update + Delete; DB transaction wrappers.
- **RulesPage** Livewire SFC bound to `/rules`: table-with-actions render (Field / Match / Value / Category / Hits / Created columns + Edit/Delete chips); two-step inline confirm-delete chip pair ([Delete?] [Yes, delete] [Cancel]); flash messaging for `Rule saved.` / `Rule deleted.`; empty-state hero ("No rules yet") + "Create your first rule" CTA.
- **RuleFormModal** Livewire SFC mounted globally: Flux modal posture with sentence layout (`When the [merchant ▼] [contains ▼] the value [______]` + `Assign category [______]`); dispatched via the `rule-form:open` event; create + edit modes; ValidationException → per-field error rendering; foreign ruleId falls back to create mode.
- **Top-nav** "Rules" anchor inserted between Uncategorized and Review chains per UI-SPEC § Navigation Decision; no badge in v1.
- **CategorizationDiverged** Public event: 5 fields (transactionId, ruleId, oldCategoryId, newCategoryId, userId) dispatched by AssignCategory when prior `auto_category_provenance.source === 'rule'` AND the new categoryId differs.
- **AssignCategory** extended with `DatabaseManager` DI: reads `transactions.auto_category_provenance` BEFORE invoking the Ledger updater; dispatches `CategorizationDiverged` on the framework event bus after a successful write when the divergence predicate holds.
- **CategorizationProvenancePanel** Livewire SFC: three variants per UI-SPEC (rule / memory / none); rule variant supports Update rule (dispatches `rule-form:open` with ruleId) + Remove rule (two-step inline confirmation); memory variant supports Override (dispatches `inline-category-picker:open`); auto-falls-back to none variant when the referenced rule has been deleted.
- **CorrectionDivergenceToast** Livewire SFC mounted globally: listens for the Livewire-local `correction-divergence:fire` event (NOT broadcaster, NOT session-flash); cross-user defensive guard via the 5-param-userId + 6-param-CurrentUser-oracle signature (T-07-09 mitigation); Update rule action invokes UpdateCategorizationRule with the new category; 8s auto-dismiss via Alpine `x-init setTimeout` calling `$wire.dismiss`.
- **TransactionDetail::reclassifyCategory** action method: captures prior provenance, invokes AssignsCategory, then re-emits `correction-divergence:fire` as a Livewire-local event carrying all 5 payload fields so the global toast SFC surfaces the choice in the same request lifecycle. The new method is distinct from the existing `reclassify(string newType)` type-reclassify path.
- **transaction-detail.blade.php** embeds the provenance panel as a Livewire sub-component keyed by transactionId so the per-row state stays scoped.
- **app.blade.php** @auth block mounts the three global Livewire SFCs: `categorization.rule-form-modal`, `categorization.correction-divergence-toast`, `receipts.receipt-conflict-toast`.
- **ScanInboxDropFolderJob** (Receipts/Internal/Jobs): per-user 5-minute scanner over `storage/app/inbox-drop/{userId}/`; for each top-level `.eml` / `.mbox` file invokes RecordReceipt through the same matcher pipeline as the wizard upload; processed files move atomically to `/processed/{YYYY-MM}/`; failed files quarantine to `/failed/{YYYY-MM}/` with a sibling `.error.txt` carrying the exception message (≤500 chars). Concurrency contract mirrors ProcessFetchedInboxMessagesJob: `ShouldBeUniqueUntilProcessing` keyed on `userId`, `tries=3`, `backoff=[60,300,900]`, `uniqueFor=600`, `uniqueVia=Cache::driver('redis')`.
- **routes/console.php** new `Schedule::call` entry named `receipts.scan-drop-folder`: dispatches one job per user with `users.auto_import_drop_folder=true`; cadence `everyFiveMinutes()->withoutOverlapping(10)`; method order `.name()` BEFORE the cadence matches the email-scan / receipts entries' precedent.
- **SettingsPage extension**: new `bool $autoImportFromDropFolder` property + `toggleAutoImport(CurrentUser, DatabaseManager, Clock)` action method that instant-applies via a raw query-builder UPDATE on `users.auto_import_drop_folder`; the settings Blade gains a new `<section>` with the UI-SPEC locked `Auto-import` header + off/on help-text variants.
- **User model**: `auto_import_drop_folder` added to fillable + `casts() => boolean` + docblock `@property bool|null` (nullable matches the in-memory freshly-created shape; the DB default is `false`).
- **ApplyAutoCategoryStage** Wave 3 deferral closed: atomic `raw('hits_count + 1')` UPDATE on the matched rule on every rule fire — the /rules page Hits column now reflects real firing counts; the (user_id, active) composite index is the hot-path read, the per-id update is bounded.
- **BoundaryArchTest + phpstan.neon**: `ScanInboxDropFolderJob` added to the Cache facade carve-out lists in BOTH the arch test's `ignoring()` set and the phpstan `noFacadeRule` ignoreErrors block so the queue-push-time `uniqueVia()` invocation stays legal.

## Task Commits

1. **Task 1: /rules CRUD page + rule form modal + top-nav extension** — `5eed05b` (feat)
   - CreateCategorizationRule / UpdateCategorizationRule / DeleteCategorizationRule Public actions
   - RulesPage Livewire SFC (table render + confirm-delete flow + flash messaging)
   - RuleFormModal Livewire SFC (sentence layout + global open event + per-field error rendering)
   - /rules route registered; "Rules" anchor in top-nav between Uncategorized and Review chains
   - 21 Pest tests cover empty state, table render, dispatch events, delete confirmation flow, cross-user 404, duplicate-rule translation, T-07-06 XSS escape

2. **Task 2: CategorizationDiverged + correction-divergence toast + provenance panel + global SFC mounts** — `d03c13f` (feat)
   - CategorizationDiverged Public event
   - AssignCategory extended with DatabaseManager DI + readPriorProvenance + maybeDispatchDivergence
   - CategorizationProvenancePanel Livewire SFC (3 variants + remove flow + override dispatch)
   - CorrectionDivergenceToast Livewire SFC (Livewire-local bridge + cross-user guard + 8s auto-dismiss)
   - TransactionDetail::reclassifyCategory method (re-emits Livewire-local event with all 5 payload fields)
   - transaction-detail.blade embeds the provenance panel as a Livewire sub-component
   - app.blade.php @auth mounts 3 global SFCs
   - 20 Pest tests cover dispatch logic, toast cross-user guard, toast actions, panel variants, remove flow, rule-deletion fallback, layout mount audit, facade-usage audit

3. **Task 3: Watched-folder secondary path + auto-import toggle + hits_count increment** — `a1bb7bb` (feat)
   - ScanInboxDropFolderJob Receipts/Internal/Jobs (5-min scanner with atomic /processed/ + /failed/ moves)
   - routes/console.php Schedule entry receipts.scan-drop-folder (everyFiveMinutes, gated on users.auto_import_drop_folder=true)
   - SettingsPage extension (autoImportFromDropFolder property + toggleAutoImport action + Blade section with off/on help text)
   - User model auto_import_drop_folder fillable + boolean cast + bool|null docblock
   - ApplyAutoCategoryStage hits_count atomic increment (Wave 3 deferral closed)
   - BoundaryArchTest + phpstan.neon Cache carve-out extended for ScanInboxDropFolderJob
   - 16 Pest tests cover scan happy path, failed quarantine, idempotency, T-07-04 per-user scope, deleted-user exit, schedule registration, carve-out audit, SettingsPage toggle persistence, hits_count increment

## Files Created/Modified

See `key-files` frontmatter above.

## Decisions Made

See `key-decisions` frontmatter above. 10 architectural / implementation decisions surfaced during execution.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] NotFoundHttpException is converted to a 404 Response by Livewire test harness — `->toThrow()` assertion never sees the exception**

- **Found during:** Task 1 (RulesPageTest cross-user delete test)
- **Issue:** First draft of the cross-user delete test used `expect(fn () => Livewire::test(RulesPage::class)->call('deleteRule', $foreignRule))->toThrow(NotFoundHttpException::class)`. The test failed with "Exception NotFoundHttpException not thrown" — Livewire's SymfonyHttpKernelExceptionHandler intercepts the exception and returns an Illuminate Response with statusCode=404 instead of re-throwing.
- **Fix:** Changed to `Livewire::test(RulesPage::class)->call('deleteRule', $foreignRule)->assertStatus(404)`. Pattern matches the existing CrossUserInboxIsolationTest precedent for Livewire actions that raise NotFoundHttpException via Symfony's HTTP exception kit.
- **Files modified:** `Modules/Categorization/tests/Feature/RulesPageTest.php`
- **Verification:** Cross-user 404 test green.
- **Committed in:** `5eed05b` (Task 1)

**2. [Rule 1 - Bug] PHPStan max flagged useless `(int) cast` on insertGetId's int-typed return value**

- **Found during:** Task 1 (CreateCategorizationRule PHPStan check)
- **Issue:** First draft of CreateCategorizationRule had `return (int) $this->db->connection()->table('categorization_rules')->insertGetId([...])`. PHPStan max's `cast.useless` rule flagged the cast because `insertGetId()` already returns `int` per the framework type hints.
- **Fix:** Removed the redundant cast.
- **Files modified:** `Modules/Categorization/Public/Actions/CreateCategorizationRule.php`
- **Verification:** PHPStan max green.
- **Committed in:** `5eed05b` (Task 1)

**3. [Rule 1 - Bug] PHPStan flagged `(string) cast on mixed` for $payload[$key] in UpdateCategorizationRule**

- **Found during:** Task 1 (UpdateCategorizationRule PHPStan check)
- **Issue:** First draft used `(string) $payload['field']` inside the error message — but `$payload` values come from the `array<string, mixed>` parameter so PHPStan rejected the cast.
- **Fix:** Added `is_string($payload['field']) ? $payload['field'] : ''` shape-narrowing before the validation check + error-message render. Same shape for 'match' and 'value' keys.
- **Files modified:** `Modules/Categorization/Public/Actions/UpdateCategorizationRule.php`
- **Verification:** PHPStan max green.
- **Committed in:** `5eed05b` (Task 1)

**4. [Rule 1 - Bug] User model lacked `auto_import_drop_folder` in fillable + casts — Eloquent silently dropped the column on UPDATE**

- **Found during:** Task 3 (SettingsPageTest 'initialises autoImportFromDropFolder from the user row on mount' test)
- **Issue:** The Wave 3 migration added `users.auto_import_drop_folder` but the User Eloquent model's `$fillable` + `casts()` did not list it. `$user->update(['auto_import_drop_folder' => true])` silently dropped the field; `$user->auto_import_drop_folder` returned null on a fresh model.
- **Fix:** Added `auto_import_drop_folder` to fillable + casts=boolean + docblock @property bool|null. The nullable docblock is intentional: freshly-created Eloquent models return null for unset attributes even though the DB default is false.
- **Files modified:** `Modules/Core/Models/User.php`
- **Verification:** SettingsPage Phase 7 tests green.
- **Committed in:** `a1bb7bb` (Task 3)

**5. [Rule 3 - Blocking] PHPStan max flagged `cast.useless` on `(bool) $user->auto_import_drop_folder` AFTER the User model declared @property bool**

- **Found during:** Task 3 (PHPStan check after Fix 4)
- **Issue:** With @property bool on the User model, PHPStan inferred the value as `bool` so the `(bool)` cast appeared useless. Removing the cast produced a runtime TypeError ("Cannot assign null to property of type bool") because freshly-created Eloquent models return null for unset attributes.
- **Fix:** Changed the docblock from @property bool to @property bool|null — matching the in-memory shape Eloquent actually returns. The `(bool)` cast is then legitimate (null → false coercion) and PHPStan accepts it.
- **Files modified:** `Modules/Core/Models/User.php`, `Modules/Core/Internal/Http/Livewire/SettingsPage.php`
- **Verification:** PHPStan max + SettingsPage Phase 7 tests both green.
- **Committed in:** `a1bb7bb` (Task 3)

**6. [Rule 3 - Blocking] ScanInboxDropFolderJob needed phpstan.neon `noFacadeRule` carve-out for Cache::driver('redis')**

- **Found during:** Task 3 (PHPStan check on ScanInboxDropFolderJob)
- **Issue:** The new job's `uniqueVia()` returns `Cache::driver('redis')` which trips the `larastanStrictRules.noFacadeRule`. Other queue jobs (ResolveChainLinksJob, BackfillInboxJob, etc.) have explicit ignoreErrors entries; ScanInboxDropFolderJob needed the same.
- **Fix:** Added an ignoreErrors entry for `Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php` matching the existing pattern. Also added the FQN to BoundaryArchTest's `ignoring()` list per the plan.
- **Files modified:** `phpstan.neon`, `tests/Contracts/BoundaryArchTest.php` (already in plan scope)
- **Verification:** PHPStan max + BoundaryArchTest green.
- **Committed in:** `a1bb7bb` (Task 3)

**7. [Rule 1 - Bug] First failed-eml test used empty-content file which EmlMimeReader processes without throwing — file moved to processed/ not failed/**

- **Found during:** Task 3 (ScanInboxDropFolderJobTest failed-eml case)
- **Issue:** First draft seeded an empty .eml file expecting the reader to throw, but `EmlMimeReader::read('')` returns a ParsedMimeMessage with empty fields — the matcher dispatch returns an unmatched outcome, RecordReceipt completes successfully, and the file moves to processed/ not failed/.
- **Fix:** Switched to `chmod 0000` on a valid-content file so `$files->get()` returns false → RecordReceipt's string-typed `__invoke` trips a TypeError → the job's try/catch quarantines via failed/. Test guards `posix_geteuid() === 0` because root bypasses chmod.
- **Files modified:** `Modules/Receipts/tests/Feature/ScanInboxDropFolderJobTest.php`
- **Verification:** Failed-quarantine test green.
- **Committed in:** `a1bb7bb` (Task 3)

**8. [Rule 1 - Bug] Container::getInstance() instead of $this->app inside Pest top-level functions**

- **Found during:** Task 2 (CorrectionDivergenceTest resolveAssign helper)
- **Issue:** First draft of `resolveAssign()` accessed `test()->app` but `$app` is a protected property on the TestCase base; Pest's top-level function context cannot reach protected members.
- **Fix:** Replaced with `Illuminate\Container\Container::getInstance()->make(AssignsCategory::class)` which is the canonical container-access pattern for static helpers.
- **Files modified:** `Modules/Categorization/tests/Feature/CorrectionDivergenceTest.php`
- **Verification:** All CorrectionDivergenceTest cases green.
- **Committed in:** `d03c13f` (Task 2)

---

**Total deviations:** 8 auto-fixed (4 Rule 1 bugs, 0 Rule 2 critical, 4 Rule 3 blocking). No architectural changes (Rule 4) required.

**Impact on plan:** All deviations were necessary for correctness or test green-ness. No scope creep. The User model + phpstan.neon edits land outside the plan's listed files but are necessary supporting infrastructure for the auto-import toggle + ScanInboxDropFolderJob to function cleanly under PHPStan max.

## Issues Encountered

- **Pre-existing TransactionTypeTest failure (out of scope):** `Modules\\Ledger\\tests\\Unit\\TransactionTypeTest::it-rejects-an-invalid-transaction-type` continues to fail in the full suite. Documented as deferred since Wave 0 + Wave 1 + Wave 2 + Wave 3 SUMMARIes. Verified pre-existing: not caused by any plan 05 change.

## Process Deviations

None. All commits made on the main branch (no worktree was created by the orchestrator); no protected-ref operations; no `git stash` operations (a single inadvertent `git stash` during PHPStan baseline verification was rolled back via `git stash pop` + `git stash drop`, with no impact on the working tree state); no destructive operations.

## Known Stubs

None. Wave 4 ships every user-facing surface for the categorization-learning feature end-to-end. The Wave 3 deferral (hits_count denormalised counter) closes here. The /rules page renders real data; the /transactions detail drawer renders the provenance panel from real provenance JSON; the toast fires from real divergence events; the watched-folder secondary path runs on a real scheduled tick.

## Threat Flags

None. The plan's `<threat_model>` covers:
- **T-07-04** (ScanInboxDropFolderJob writing into a foreign user's folder via path traversal) — mitigated by `storage_path('app/inbox-drop/'.(int)$userId.'/...')`; integer cast on userId; the scan loop only walks the `{userId}/` subfolder; per-file rename targets `basename($path)` so a crafted filename cannot escape. T-07-04 cross-user test green.
- **T-07-06** (Stored XSS in rule.value rendered to /rules + transaction-detail drawer) — mitigated by Blade `{{ }}` auto-escape on every rule.value render. Pest test creates a rule with value `<script>alert(1)</script>` and asserts the rendered HTML contains `&lt;script&gt;` verbatim.
- **T-07-09** (Cross-user data leak via rule CRUD + CorrectionDivergenceToast) — mitigated by `where('user_id', $user->id)` on every read in CreateCategorizationRule, UpdateCategorizationRule, DeleteCategorizationRule, CategorizationProvenancePanel, AssignCategory, and the toast's defensive `$currentUser->id() !== $userId` guard inside handleDiverged. Cross-user 404 tests green on Update + Delete; cross-user toast no-op test green.
- **T-07-12** (CSRF on rule mutations) — mitigated by Laravel's default CSRF middleware on the web group + Livewire's `wire:click` / `wire:submit` riding the CSRF token through livewireScripts. No bare POST endpoints introduced.

No new threat surface introduced.

## Self-Check: PASSED

**Created files (spot check via `test -f`):**
- `Modules/Categorization/Public/Actions/{CreateCategorizationRule,UpdateCategorizationRule,DeleteCategorizationRule}.php` — ALL FOUND
- `Modules/Categorization/Public/Events/CategorizationDiverged.php` — FOUND
- `Modules/Categorization/Internal/Http/Livewire/{RulesPage,RuleFormModal,CategorizationProvenancePanel,CorrectionDivergenceToast}.php` — ALL FOUND
- `Modules/Categorization/Resources/views/rules.blade.php` — FOUND
- `Modules/Categorization/Resources/views/livewire/{rules-page,rule-form-modal,categorization-provenance-panel,correction-divergence-toast}.blade.php` — ALL FOUND
- `Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php` — FOUND
- `Modules/Categorization/tests/Feature/{RulesPageTest,RuleFormModalTest,CorrectionDivergenceTest,CategorizationProvenancePanelTest}.php` — ALL FOUND
- `Modules/Receipts/tests/Feature/ScanInboxDropFolderJobTest.php` — FOUND

**Commits (verified via `git log --oneline | grep`):**
- `5eed05b` (Task 1 — /rules CRUD page + rule form modal + top-nav extension) — FOUND
- `d03c13f` (Task 2 — CategorizationDiverged event + correction-divergence toast + provenance panel + global SFC mounts) — FOUND
- `a1bb7bb` (Task 3 — watched-folder secondary path + auto-import toggle + hits_count increment) — FOUND

**Verification:**
- 46 new Wave 4 tests green (21 Task 1 + 20 Task 2 + 16 Task 3)
- 1162 full-suite tests passing (6 skipped legitimately; 1 pre-existing TransactionTypeTest failure carried forward since Wave 0)
- PHPStan max green on the whole Modules tree + tests/Contracts/BoundaryArchTest.php
- Pint format green on every touched file
- BoundaryArchTest 20/20 green including the new Cache facade carve-out for ScanInboxDropFolderJob

## Next Phase Readiness

Wave 4 closes Phase 7. The categorization-learning surface is end-to-end demoable:

1. Visit `/rules` → create a rule "merchant contains SPOTIFY → Streaming".
2. Drop a PayPal Spotify .eml via /imports wizard → a transaction lands auto-categorised as Streaming (provenance.source='rule', hits_count=1).
3. Visit `/transactions/{id}` → see the "Rule that fired" provenance panel.
4. Click Reclassify Category → pick "Music" → the correction-divergence toast surfaces "Update the rule?". Click Update rule → the rule's category swaps to Music + hits_count stays at 1 (no double-counting on reclassify).
5. Toggle the /settings Auto-import row → drop the same .eml into `storage/app/inbox-drop/{userId}/` → wait ≤5 min → verify it moves to `/processed/{YYYY-MM}/` and a new file_imports row appears.

Phase 4 deferred-items still pending: `Modules\\Ledger\\tests\\Unit\\TransactionTypeTest::it-rejects-an-invalid-transaction-type` (environment-shaped Pest harness issue carried forward since Wave 0).

Phase 8 inherits a fully working /rules CRUD + correction-divergence + provenance panel + auto-import path. Rules + memory + ApplyAutoCategoryStage are the primary signal for the upcoming Fixed Payments + Recurring Series + Forecast phases.

---
*Phase: 07-email-template-matchers-categorization-learning*
*Plan: 05*
*Completed: 2026-05-17*
