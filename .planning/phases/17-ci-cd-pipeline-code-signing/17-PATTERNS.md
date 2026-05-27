# Phase 17: v1.0.0 Public Release Closeout — Pattern Map

**Mapped:** 2026-05-27
**Files analyzed:** ~55 files (across 9 areas — CI/CD, auto-update, Counterparties module, Core/Health, arch invariants, community docs, `.docs/` tree, git-history purge, skill rename)
**Analogs found:** ~38 with strong matches / ~55 total. The remaining 17 are
either community docs with no internal analog (LICENSE, SECURITY.md, etc.) or
out-of-codebase tasks (git filter-repo, skill rename, GitHub UI walkthrough).

---

## File Classification

### Area 1 — CI/CD pipeline (Section B)

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `.github/workflows/ci.yml` (MODIFY: matrix widen) | config (CI) | event-driven (push/PR) | itself — in-place modify | self |
| `.github/workflows/release.yml` (NEW) | config (CI) | event-driven (tag push) | `.github/workflows/ci.yml` | role-match (CI workflow) |
| `.github/workflows/security.yml` (NEW — gitleaks; OR inline into ci.yml) | config (CI) | event-driven (PR/push) | `.github/workflows/ci.yml` | role-match |
| `.github/CODEOWNERS` (NEW) | config (governance) | static-config | none in-repo (GitHub convention) | no analog |
| `.env.bundled` (NEW) | config (template) | static-config | repo `.env.example`-style (none committed; check) | no analog |
| `config/nativephp.php` (MODIFY: version default → `'0.0.0-dev'`) | config | static-config | itself | self |
| `scripts/nativephp_force_adhoc_signing.php` (UNCHANGED per A-01) | utility (prebuild hook) | file-I/O (patches JS) | itself — UNCHANGED | self |

### Area 2 — Auto-update plumbing (Section C — D-19..D-22 + A-06)

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Core/Public/Services/ElectronUpdateChannel.php` (NEW) | service (adapter) | event-driven (subscribe to updater events) | `Modules/Core/Public/Services/SystemAlertQuery.php` | role-match (Public Service, DI shape) |
| 3 new `system_alerts.kind` rows (`update.available` / `update.stale` / `update.critical`) | data (seed-like) | static-config | existing `system_alerts.kind` rows in `Modules/Core/Internal/Listeners/HealthCheckListener.php` | role-match |
| `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` (MODIFY: 3 new alert kinds) | view (Blade) | request-response | itself | self |
| `Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php` (MODIFY: 3 new kinds) | view (partial) | request-response | itself | self |
| `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` (MODIFY: new `skipVersion($alertId)` wire method) | component (Livewire) | request-response | itself | self |
| Migration: add `skipped_update_versions` to `user_preferences` (or new column on users) | migration | one-shot DDL | `Modules/Categorization/Database/Migrations/2026_05_17_010003_create_categorization_rules_table.php` | role-match (Module migration with triggers + container-DI pattern) |
| Pest test: tampered-manifest verification fails | test (Unit) | request-response | `Modules/Desktop/tests/Unit/ForceAdhocSigningScriptTest.php` | role-match (script-level static fixture) |

### Area 3 — `/health` route + Controller (Section B — D-13/D-14)

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Core/Public/Controllers/HealthController.php` (NEW) | controller | request-response (JSON) | `Modules/Desktop/Internal/Http/CloseActionController.php` | role-match (single-action `__invoke` controller with DI) |
| `Modules/Core/Routes/web.php` (MODIFY: add `/health` route) | config (route) | request-response | itself | self |
| Pest test: `/health` returns 200 + correct shape | test (Feature) | request-response | `Modules/Core/tests/Feature/AppBootHealthCheckTest.php` | exact (HealthCheck-themed Feature test) |

### Area 4 — CI-06 first-launch APP_KEY sentinel

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Core/Internal/Bootstrap/EnsureAppKey.php` (NEW) | service (internal) | file-I/O (sentinel-file check) | `Modules/Desktop/Internal/Native/FirstLaunchBootstrap.php` | exact (first-launch bootstrap; UserDataPathService DI; constructor-injected Migrator/DB) |
| Pest test for `EnsureAppKey` (sentinel-absent → generates key) | test (Feature) | request-response | `Modules/Desktop/tests/Feature/FirstLaunchBootstrapTest.php` | role-match |

### Area 5 — Counterparties module (Section I — D-43..D-48 + A-04 + A-08)

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Counterparties/composer.json` | config (module) | static-config | `Modules/Onboarding/composer.json` | exact |
| `Modules/Counterparties/module.json` | config (module) | static-config | `Modules/Categorization/module.json` | exact |
| `Modules/Counterparties/Providers/CounterpartiesServiceProvider.php` (NEW) | provider | event-driven (boot) | `Modules/Onboarding/Providers/OnboardingServiceProvider.php` | exact |
| `Modules/Counterparties/Routes/web.php` (NEW) | config (route) | request-response | `Modules/Onboarding/Routes/web.php` | exact |
| `Modules/Counterparties/Database/Migrations/XXXX_create_counterparties_table.php` (NEW) | migration | one-shot DDL | `Modules/Categorization/Database/Migrations/2026_05_17_010003_create_categorization_rules_table.php` | exact (status-enum table with triggers, user_id FK cascade, BelongsToUser-friendly indexes) |
| Migration: `add_counterparty_id_to_transactions` (FK nullable, index) | migration | one-shot DDL | `Modules/Ledger/Database/Migrations/2026_05_13_010003_add_enriched_count_to_import_runs.php` | role-match (additive column on existing table) |
| `Modules/Counterparties/Models/Counterparty.php` (NEW) | model (Eloquent) | CRUD | `Modules/Onboarding/Models/WizardProgress.php` | exact (BelongsToUser, status/type enum, JSON metadata cast, explicit phpdoc property block) |
| `Modules/Counterparties/Public/Contracts/CounterpartyResolver.php` (NEW) | contract (interface) | request-response | `Modules/Categorization/Public/Contracts/AppliesAutoCategory.php` | exact (single-method Public contract used cross-module via DI) |
| `Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php` (NEW) | service (resolver) | request-response | `Modules/Import/Public/Services/MerchantNameResolver.php` | exact (precedence-chain resolver; raw DB + scope-by-user; constructor DI) |
| `Modules/Counterparties/Internal/Pipeline/ResolveCounterpartyStage.php` (NEW) | service (pipeline stage) | transform (per-row) | `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php` | exact (pipeline stage with `run(CanonicalTransaction $tx, User $user)` signature, side-effect-free, pre-classification) |
| `Modules/Import/Internal/Pipeline/ImportPipeline.php` (MODIFY: insert new stage between `ApplyAutoCategory` and post-commit boundary) | service (orchestrator) | transform | itself | self |
| `Modules/Counterparties/Internal/Jobs/CounterpartyGarbageCollectorJob.php` (NEW) | job (queued) | batch | `Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php` | exact (ShouldBeUniqueUntilProcessing, LockStore, backoff, constructor DI in `handle`) |
| `Modules/Counterparties/Internal/Listeners/InitializeCounterpartySeedOnInstall.php` (optional — if seed defaults) | listener (event) | event-driven | `Modules/Onboarding/Internal/Listeners/InitializeWizardProgressOnInstall.php` | exact (single-method readonly listener on UserInstalled event) |
| `Modules/Counterparties/Public/Events/CounterpartyResolved.php` (NEW) | event (DTO) | event-driven | `Modules/Core/Public/Events/UserInstalled.php` | exact (final readonly class with public readonly props) |
| Pest unit test `CounterpartyResolverTest` (NEW) | test (Unit) | request-response | `Modules/Onboarding/tests/Unit/ResumeStepResolverTest.php` | exact (`RefreshDatabase` trait + beforeEach user setup + service-under-test pulled via `$this->app->make()`) |
| Pest unit test `PrivacyDefaultsTest` (asserts IBAN never in lists/URLs/titles for `personal`) | test (Unit) | request-response | `Modules/Onboarding/tests/Unit/ResumeStepResolverTest.php` | role-match |
| Pest unit test `SlugCollisionTest` (collision suffixing `-2`, `-3`) | test (Unit) | request-response | `Modules/Onboarding/tests/Unit/ResumeStepResolverTest.php` | role-match |
| Pest feature tests: index / profile / triage / pipeline-stage smoke | test (Feature) | request-response | `Modules/Categorization/tests/Unit/RuleEvaluatorSpecificityTest.php` | role-match |

### Area 6 — Counterparty UI surfaces (Section I + UI-SPEC)

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Counterparties/Internal/Http/Livewire/CounterpartyIndex.php` (NEW) | component (Livewire) | request-response | `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` | role-match (method-parameter DI on `render()` — phpstan-strict-rules ban on constructor DI in Livewire) |
| `Modules/Counterparties/Resources/views/livewire/counterparty-index.blade.php` (NEW) | view (Blade) | request-response | `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` | role-match (Tailwind-literal-class strings, role/aria attributes, escape-all-output policy) |
| `Modules/Counterparties/Internal/Http/Livewire/CounterpartyProfile.php` (NEW) | component (Livewire) | request-response | `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` | role-match |
| `Modules/Counterparties/Resources/views/livewire/counterparty-profile.blade.php` + 6 per-type partial views | view (Blade) | request-response | `Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php` | role-match (page-shell + variant body pattern) |
| `Modules/Counterparties/Internal/Http/Livewire/CounterpartyTriage.php` (NEW) | component (Livewire) | request-response | `Modules/Community/Resources/views/livewire/mystery-merchants-page.blade.php` (companion `.php` is the analog) | role-match (triage-queue pattern) |
| `Modules/Counterparties/Resources/views/components/{type-chip,cp-card,filter-chips,frame,chain-flow,iban-row,privacy-banner,self-stub}.blade.php` | view (Blade x-components) | request-response | `Modules/Onboarding/Resources/views/components/consolidated-preview-section.blade.php` | role-match |
| `Modules/Core/Resources/views/livewire/app-sidebar.blade.php` (MODIFY: add Counterparties + Triage entries under MONEY) | view (Blade) | request-response | itself (existing sidebar already declares `Recurring`/`Chains` under `MONEY` section) | self |
| `resources/css/app.css` (MODIFY: add `@layer components` for `.t-*`, `.cp-card`, `.frame`, `.triage-card`, `.privacy-banner`, etc.) | view (CSS) | static-config | itself | self |

### Area 7 — Arch invariants (D-27 `noGsdLeakage` + D-29 `noSecretsInLivewireSnapshot`)

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Core/Public/Services/SecretsColumnRegistry.php` (NEW) | service (registry) | static-config | `Modules/Core/Public/Services/UserDataPathService.php` (static accessor pattern with instance shim) | role-match (single-source-of-truth registry) |
| `tests/Contracts/GsdLeakageTest.php` OR `Modules/Core/Tests/Boundary/GsdLeakageTest.php` (NEW) | test (arch invariant) | batch (file-tree walk) | `tests/Contracts/BoundaryArchTest.php` — specifically the `noPaypalApiRoute` + `noStoragePathHardCodedOutsideUserDataPathService` shapes | exact (recursive directory walk + grep + comment-strip + allow-list) |
| `tests/Contracts/SecretsInLivewireSnapshotTest.php` (NEW) | test (arch invariant) | batch (reflection walk) | `tests/Contracts/BoundaryArchTest.php` (test-it style with reflection) | role-match — reflection over Livewire components is a NEW shape not currently in repo; piggyback the comment-strip + allow-list helpers from BoundaryArchTest |

### Area 8 — Community docs at repo root (Section D — D-23..D-25 + A-05)

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `LICENSE` (NEW — Hippocratic 3.0 verbatim) | docs (legal) | static | external: `firstdonoharm.dev` (per CONTEXT canonical_refs) | no internal analog |
| `NOTICE.md` (NEW — source-available explainer) | docs | static | none in-repo | no internal analog |
| `SECURITY.md` (NEW) | docs (governance) | static | none in-repo | no internal analog |
| `CONTRIBUTING.md` (NEW) | docs (governance) | static | none in-repo; pull DI-rule + arch-test guidance from `CLAUDE.md` | partial — `CLAUDE.md` is the source-of-truth for the DI rule + arch tests |
| `CODE_OF_CONDUCT.md` (NEW — Contributor Covenant 2.1 verbatim) | docs (governance) | static | external: `contributor-covenant.org/version/2/1/code_of_conduct/` | no internal analog |
| `README.md` (REWRITE) | docs | static | itself (existing README is `beatrax`-themed but pre-public — rewrite per REL-03; UI-SPEC has verbatim install-bypass copy) | self / external |
| `composer.json` (MODIFY: `"license": "Hippocratic-3.0"`, may need `"license-validation": false` — see RESEARCH REL-01 caveat) | config | static | itself | self |
| `resources/brand/` (generate `.icns`, `.ico`, `logo-512.png`, favicon from existing SVG) | asset | static | `scripts/regenerate_app_icon.php` (existing helper) | exact |

### Area 9 — `.docs/` tree (D-31..D-34)

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `.docs/00-index.md` + all subtree `00-index.md` files | docs (navigation) | static | external: `https://github.com/happklaar/happklaar` (per CONTEXT D-31) | external mirror — no in-repo pattern |
| `.docs/adr/0001-modular-architecture.md` … `0020+-…md` (ADRs) | docs (ADR) | static | external | external mirror |
| `.docs/architecture/<topic>.md` files | docs | static | external | external mirror |
| `.docs/features/_template/{architecture,code,specs,how-to-test}.md` + 17 per-module subdirs | docs (per-feature) | static | external (happklaar `features/_template/`) | external mirror |
| `.docs/cicd/{overview,branch-protection,release-workflow,release-cadence}.md` | docs (CI/CD) | static | partially-seeded: `.docs/cicd/branch-protection.md` already exists | partial (stub-extant) |
| `.docs/local_development/{setup,database,troubleshooting,dev-mode}.md` | docs | static | source material: `CLAUDE.md` tech-stack section + Phase 15 bootstrap learnings | partial (source-extant) |
| `.docs/runbooks/{release-cut,verify-release,repo-security-setup,force-password-reset}.md` | docs (runbooks) | static | partially-seeded: `.docs/runbooks/repo-security-setup.md` already exists | partial (stub-extant) |
| `.docs/legal/{license-rationale,data-retention}.md` | docs | static | none in-repo | no internal analog |
| `.docs/roadmap-v1.1.md` | docs | static | none in-repo | no internal analog |

### Area 10 — `/help/data-locations` page (D-30 / REL-08)

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Core/Internal/Http/Livewire/HelpDataLocations.php` (NEW) | component (Livewire) | request-response | `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` | role-match (Livewire Component, method-param DI on `render()`, injects `UserDataPathService`) |
| `Modules/Core/Resources/views/livewire/help/data-locations.blade.php` (NEW) | view (Blade) | request-response | `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` | role-match |
| `Modules/Core/Routes/web.php` (MODIFY: add `/help/data-locations` route) | config (route) | request-response | itself | self |

### Area 11 — Git history purge + repo hygiene (D-35..D-37 + A-07)

| Task | Role | Data Flow | Closest Analog | Match Quality |
|------|------|-----------|----------------|---------------|
| `git filter-repo --path .planning --invert-paths` | git-op | one-shot | none in-repo | no analog (out-of-source operation) |
| `.gitignore` (MODIFY: add `.planning/`) | config | static-config | itself | self |
| Delete every existing tag (`git tag | xargs git tag -d`) | git-op | one-shot | none in-repo | no analog |
| `v0.1.0` (or `v0.1.0-rc.1`) tag push | git-op | one-shot | none in-repo | no analog |
| `v1.0.0` graduation tag (user-triggered) | git-op | one-shot | none in-repo | no analog |

### Area 12 — Skill rename (D-40..D-42)

| Task | Role | Data Flow | Closest Analog | Match Quality |
|------|------|-----------|----------------|---------------|
| `./.claude/skills/sketch-findings-diederik/` → `./.claude/skills/sketch-findings-beatrax/` | file-rename | one-shot | **Already done** — current state of repo shows `sketch-findings-beatrax/` at the project-level path; the `git status` line `D .claude/skills/sketch-findings-diederik/SKILL.md` confirms the rename is staged | self (mid-rename) |
| `~/.claude/skills/sketch-findings-diederik/` → `~/.claude/skills/sketch-findings-beatrax/` (user-level) | file-rename | one-shot | n/a — outside repo | no analog |
| `CLAUDE.md` "Project Skills" reference update | docs | static | itself | self |
| Frontmatter `name:` field in `SKILL.md` | docs | static | itself | self |

### Area 13 — GitHub repo settings walkthrough (Section J — D-49..D-50 + A-03)

| Task | Role | Data Flow | Closest Analog | Match Quality |
|------|------|-----------|----------------|---------------|
| Interactive walkthrough on `nightworksio/beatrax` | conversational | n/a | none in-repo (web-UI work) | no analog |
| Captured config in `.docs/cicd/branch-protection.md` (already stub-extant) | docs | static | partial: file already stubbed | self |
| Captured config in `.docs/runbooks/repo-security-setup.md` (already stub-extant) | docs | static | partial: file already stubbed | self |

---

## Pattern Assignments

### `Modules/Counterparties/Models/Counterparty.php` (model, CRUD)

**Analog:** `Modules/Onboarding/Models/WizardProgress.php` (also `Modules/Categorization/Models/CategorizationRule.php` for the `BelongsToUser` posture + per-property phpdoc block).

**Imports + class header pattern** (`WizardProgress.php` lines 1-10):

```php
<?php

declare(strict_types=1);

namespace Modules\Counterparties\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;
```

**Body pattern** (lines 42-68):

```php
final class Counterparty extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'counterparties';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',          // merchant|personal|bank|government|self_account|unknown
        'slug',
        'display_name',
        'iban',
        'merchant_name',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
```

**Cross-user posture phpdoc** (paste verbatim from `KnownCounterpartyIban.php` lines 12-27 — the explicit-where-on-user_id docstring is exactly the contract D-43 + D-45 want).

---

### `Modules/Counterparties/Public/Contracts/CounterpartyResolver.php` (contract, request-response)

**Analog:** `Modules/Categorization/Public/Contracts/AppliesAutoCategory.php`

**Full file pattern** (lines 1-31 of the analog):

```php
<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Counterparties\Public\Dto\CounterpartyResolutionDto;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Public Counterparties contract — ImportPipeline + Ledger/Recurring/
 * Chains consume via DI. The default implementation
 * (`CounterpartyResolverService`) is bound in CounterpartiesServiceProvider.
 *
 * Implementations MUST be side-effect-free on stage failure: a buggy
 * resolver returns a null result rather than aborting the import.
 */
interface CounterpartyResolver
{
    public function resolve(CanonicalTransaction $tx, User $user): ?CounterpartyResolutionDto;
}
```

---

### `Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php` (service, request-response)

**Analog:** `Modules/Import/Public/Services/MerchantNameResolver.php` (precedence-chain class) + `Modules/Import/Internal/Services/KnownCounterpartyIbanResolver.php` (the alias-bridge integration; D-44 says the new resolver wraps this for `bank` type per A-08).

**Constructor DI pattern** (`MerchantNameResolver.php` lines 68-71):

```php
public function __construct(
    private readonly DatabaseManager $db,
    private readonly CommunityCorpusQuery $corpus,        // for counterparties: ResolvesKnownCounterpartyIban + MerchantNameResolver + ... see resolution chain (7 steps)
) {}
```

**Precedence-chain walk** (lines 78-115 — fall-through pattern; first match wins):

```php
public function resolve(string $rawDescription, int $userId): ?string
{
    $connection = $this->db->connection();

    // Step 1: user's exact alias
    $exact = $connection->table('merchant_aliases')
        ->where('user_id', $userId)
        ->where('pattern', $rawDescription)
        ->value('friendly_name');
    if (is_string($exact) && $exact !== '') {
        return $exact;
    }

    // Step 2: user's generalized alias (bounded scan, mb_strpos)
    // …

    // Step 3, 4, 5: fallback steps in precedence order
    // …

    return null;  // unresolved
}
```

For Phase 17, the 7-step resolution chain documented in Section I of CONTEXT.md maps to this same shape: self-account check → known_counterparty_iban → MerchantNameResolver → personal-IBAN heuristic → government-keyword → bank-fee → unresolved.

**Cross-user safety** (`MerchantNameResolver.php` lines 81-85, `KnownCounterpartyIbanResolver.php` lines 42-46): every query gets an explicit `->where('user_id', $userId)`. The BelongsToUser global scope is the secondary guard; the explicit filter is load-bearing for queue/console contexts.

---

### `Modules/Counterparties/Internal/Pipeline/ResolveCounterpartyStage.php` (service, transform)

**Analog:** `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php` (existing pipeline stage; same signature, same side-effect-free contract).

**Class header + DI pattern** (lines 71-78 of the analog):

```php
final class ResolveCounterpartyStage
{
    public function __construct(
        private readonly CounterpartyResolver $resolver,        // Public contract — DI'd; not the Internal class
        private readonly DatabaseManager $db,
    ) {}

    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction
    {
        // Idempotent: same input → same counterparty_id; cheap upsert
        // on `counterparties` table; attach FK to outgoing $tx.
        // Pure pre-load transformer — never queries the `transactions` table.
    }
}
```

**ImportPipeline insertion point** — modify `Modules/Import/Internal/Pipeline/ImportPipeline.php` constructor signature (lines 54-66) and the per-row loop (`preview()` method): insert `$counterpartyResolveStage->run($tx, $user)` between `$autoCategory->apply(...)` and `$fingerprint->run(...)`. The pipeline already takes `MerchantNameResolver` via DI (line 63), so adding one more DI'd stage is a mechanical change.

---

### `Modules/Counterparties/Internal/Jobs/CounterpartyGarbageCollectorJob.php` (job, batch)

**Analog:** `Modules/DriftAlerts/Internal/Jobs/DetectDriftAlertsJob.php`

**Full class shape** (lines 40-77):

```php
final class CounterpartyGarbageCollectorJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $userId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->userId}";
    }

    public function uniqueFor(): int
    {
        return 3600;  // 1h — daily-scheduled job, but uniqueness window covers a single tick
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(/* inject the collector service */): void
    {
        // Prune counterparties with zero transactions in the last 365 days
        // AND zero alias entries (suggested in CONTEXT Claude's Discretion).
    }
}
```

The `LockStore::forUniqueJobs()` carve-out is already on the `noLaravelFacadeUsageInModule` allow-list (`tests/Contracts/BoundaryArchTest.php` lines 92-93) — no new arch-test edit needed.

---

### `Modules/Counterparties/Providers/CounterpartiesServiceProvider.php` (provider, event-driven)

**Analog:** `Modules/Onboarding/Providers/OnboardingServiceProvider.php`

**Full file pattern** (lines 1-80 of the analog):

```php
<?php

declare(strict_types=1);

namespace Modules\Counterparties\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;
use Modules\Counterparties\Internal\Resolver\CounterpartyResolverService;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;

final class CounterpartiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CounterpartyResolver::class, CounterpartyResolverService::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $routesPath = __DIR__.'/../Routes/web.php';
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        $viewsPath = __DIR__.'/../Resources/views';
        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, 'counterparties');
        }

        $livewire->component('counterparties.index', CounterpartyIndex::class);
        $livewire->component('counterparties.profile', CounterpartyProfile::class);
        $livewire->component('counterparties.triage', CounterpartyTriage::class);
    }
}
```

---

### `Modules/Counterparties/Database/Migrations/XXXX_create_counterparties_table.php` (migration, one-shot DDL)

**Analog:** `Modules/Categorization/Database/Migrations/2026_05_17_010003_create_categorization_rules_table.php`

**Full skeleton pattern** (lines 1-121 of the analog — copy verbatim; substitute table name + columns):

```php
<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the counterparties table — one row per (user_id, slug).
 *
 * Allowed `type` values: merchant|personal|bank|government|self_account|unknown.
 * Enforced via paired BEFORE INSERT / BEFORE UPDATE OF type triggers.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('counterparties', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('slug', 128);
            $table->string('display_name');
            $table->string('iban', 64)->nullable();
            $table->string('merchant_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'type']);
        });

        // Type-enum triggers — same pattern as wizard_progress.status,
        // categorization_rules.field, etc.
        $connection = $this->db()->connection($this->getConnection());
        $allowedTypes = "'merchant','personal','bank','government','self_account','unknown'";
        $connection->statement(sprintf(
            "CREATE TRIGGER counterparties_type_check_insert BEFORE INSERT ON counterparties FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid counterparties.type value'); END",
            $allowedTypes,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER counterparties_type_check_update BEFORE UPDATE OF type ON counterparties FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid counterparties.type value'); END",
            $allowedTypes,
        ));
    }

    public function down(): void { /* drop triggers + table */ }

    private function schema(): Builder { /* same as analog */ }
    private function db(): DatabaseManager { /* same as analog */ }
};
```

**Companion migration** `add_counterparty_id_to_transactions`: copy from `Modules/Ledger/Database/Migrations/2026_05_13_010003_add_enriched_count_to_import_runs.php` for the additive-column pattern; FK is `cascadeOnDelete from user_id`, NOT from counterparty (per D-45 — orphans pruned by the GC job).

---

### `Modules/Counterparties/Routes/web.php` (config, request-response)

**Analog:** `Modules/Onboarding/Routes/web.php`

**Pattern** (lines 1-29 of analog):

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/counterparties', CounterpartyIndex::class)->name('counterparties.index');
    Route::get('/counterparties/triage', CounterpartyTriage::class)->name('counterparties.triage');
    Route::get('/counterparties/{slug}', CounterpartyProfile::class)->name('counterparties.profile');
});
```

Note ordering — `/triage` must come BEFORE `/{slug}` so the literal segment matches first.

---

### `Modules/Counterparties/Internal/Http/Livewire/CounterpartyIndex.php` (component, request-response)

**Analog:** `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php`

**Component shape** (full file, lines 33-52):

```php
final class CounterpartyIndex extends Component
{
    // NOTE: No constructor DI on Livewire Component subclasses
    // (phpstan-strict-rules bans it). Use method-parameter DI on
    // render() and on each wire action instead.

    public string $type = 'all';      // filter chip
    public string $view = 'cards';    // cards|list

    public function render(
        CurrentUser $currentUser,
        CounterpartyIndexQuery $query,    // new Public read query (per D-46/A-04)
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $rows = $query->forUser($user, $this->type);

        return $views->make('counterparties::livewire.counterparty-index', [
            'rows' => $rows,
        ]);
    }
}
```

The `CounterpartyIndex` page lives at `/counterparties` route alias `counterparties.index`. Page-level Livewire components extend `Component` and are wired via `Volt::route(...)` OR `Route::get(... ClassName::class ...)` (the project uses the latter — `Modules/Onboarding/Routes/web.php` line 27 shows the route binding to the Livewire class directly).

---

### `Modules/Counterparties/Resources/views/livewire/counterparty-profile.blade.php` (view, request-response)

**Analog:** `Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php` (per-record profile-shell pattern).

For the per-type sub-views (`profile-tabs/merchant.blade.php`, etc.) the contract is `<x-counterparties::frame>` or similar Blade x-component wrapping per UI-SPEC's component inventory.

---

### `Modules/Core/Public/Controllers/HealthController.php` (controller, request-response)

**Analog:** `Modules/Desktop/Internal/Http/CloseActionController.php` (single-action `__invoke` controller with DI; minimal surface).

**Full pattern** (lines 29-65 of analog, adapted):

```php
<?php

declare(strict_types=1);

namespace Modules\Core\Public\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;

/**
 * Auth-free health probe used by the release.yml smoke tests
 * (CI runner curls this after launching the installed bundle).
 *
 * Returns deterministic shape (no timestamps — per D-14 NO timestamp
 * so deterministic-assertion smoke tests stay green across re-runs).
 */
final class HealthController
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(): JsonResponse
    {
        $sqliteVersion = (string) $this->db->connection()->scalar('SELECT sqlite_version()');

        return new JsonResponse([
            'status' => 'ok',
            'app_version' => (string) getenv('NATIVEPHP_APP_VERSION') ?: 'dev',
            'php_version' => PHP_VERSION,
            'sqlite_version' => $sqliteVersion,
        ]);
    }
}
```

**Route registration** (`Modules/Core/Routes/web.php`):

```php
Route::get('/health', HealthController::class)->name('core.health');
// NO 'web', 'auth' middleware — smoke test calls before any user exists
```

---

### `Modules/Core/Public/Services/ElectronUpdateChannel.php` (service, event-driven adapter)

**Analog (structure):** `Modules/Core/Public/Services/SystemAlertQuery.php` (Public Service, DI, `final readonly class`).

**Pattern** (lines 37-41 of analog):

```php
namespace Modules\Core\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Modules\Core\Public\Contracts\Clock;

/**
 * Thin Laravel-side adapter over electron-updater (vendored via NativePHP).
 *
 * Exposes channel selection (stable / preview) + the "update available"
 * / "update stale" / "update critical" events the SystemAlertsBanner
 * subscribes to via system_alerts rows.
 *
 * Ed25519 manifest verification (UPDATE-02, A-06) happens here BEFORE
 * raising any system_alerts row — verify via sodium_crypto_sign_verify_detached
 * against the in-bundle public key (compiled-in constant).
 */
final readonly class ElectronUpdateChannel
{
    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $logger,
        private Clock $clock,                    // existing Public contract
    ) {}

    public function poll(/* … */): void { /* … */ }
    public function isStale(string $currentVersion, string $latestVersion): bool { /* … */ }
}
```

The Ed25519 verification is PHP-side via `sodium_crypto_sign_verify_detached` (built into PHP 8.4+; no new composer dependency). The public key is embedded as a compiled-in constant in this class.

---

### `Modules/Core/Public/Services/SecretsColumnRegistry.php` (service, static-config)

**Analog:** `Modules/Core/Public/Services/UserDataPathService.php` (static accessor pattern with instance shim; single source of truth).

**Pattern** (lines 25-48 of analog):

```php
final class SecretsColumnRegistry
{
    /**
     * Enumerate every column the renderer-JSON arch invariant treats
     * as a secret. New entries land here and the arch test re-fires.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'oauth_secrets.access_token',
            'oauth_secrets.refresh_token',
            'oauth_secrets.client_secret',
            // … add as new secrets-bearing columns appear
        ];
    }

    // Instance surface for DI consumers
    /** @return list<string> */
    public function all(): array
    {
        return self::columns();
    }
}
```

---

### `tests/Contracts/GsdLeakageTest.php` (arch invariant, batch)

**Analog:** `tests/Contracts/BoundaryArchTest.php` — specifically the `noPaypalApiRoute` shape (lines 262-303) and the `noStoragePathHardCodedOutsideUserDataPathService` shape (lines 1147-1212).

**Pattern (copy verbatim, swap the regex)** (lines 262-303 of analog):

```php
it('does not allow GSD planning artefacts to leak into runtime code (noGsdLeakage)', function (): void {
    // REL-05 / D-27: runtime PHP code, Blade views, route names, view-data
    // keys, error messages, log lines, and comments must NEVER reference
    // .planning/, PLAN.md, RESEARCH.md, D-NNN codenames, gsd-prefixed
    // identifiers, or GSD phase codenames.
    //
    // Pattern matches:
    //   .planning/, PLAN.md, RESEARCH.md, CONTEXT.md, REVIEW.md,
    //   \bD-\d{2,3}\b, gsd[-_], and phase-codename prefixes
    //   (16-1, 17-04, etc.).
    //
    // Strips block + line + Blade comments first so legitimate
    // architectural notes that name a phase number — none should
    // exist in production code after the redaction sweep — fail loud.
    $hits = [];

    foreach (['routes', 'Modules', 'config', 'app'] as $root) {
        $absoluteRoot = base_path($root);
        if (! is_dir($absoluteRoot)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $absoluteRoot,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (preg_match('/\.(php|blade\.php)$/', $path) !== 1) {
                continue;
            }
            if (str_contains($path, '/tests/')) {
                continue;   // test files reference the planning corpus by name
            }
            $contents = (string) file_get_contents($path);
            $stripped = preg_replace('#/\*.*?\*/|//[^\n]*|\{\{--.*?--\}\}#s', '', $contents) ?? $contents;
            if (
                preg_match('/\.planning\/|PLAN\.md|RESEARCH\.md|CONTEXT\.md|REVIEW\.md|\bD-\d{2,3}\b|gsd[-_]/i', $stripped) === 1
            ) {
                $hits[] = $path;
            }
        }
    }

    expect($hits)->toBe(
        [],
        "No GSD planning artefacts may leak into runtime code. Offenders:\n  ".implode("\n  ", $hits)
    );
});
```

---

### `tests/Contracts/SecretsInLivewireSnapshotTest.php` (arch invariant, batch)

**Analog (carve-out + recursive-walk shape):** `tests/Contracts/BoundaryArchTest.php` lines 1147-1212 (the `noStoragePathHardCodedOutsideUserDataPathService` shape — recursive walk + symbol grep + allow-list).

**Net-new shape consideration:** Reflection-walking Livewire components is not currently a pattern in the codebase. The test reuses BoundaryArchTest's recursive directory walk + comment-strip, but the grep step is replaced with a reflection-driven check that, for every class extending `Livewire\Component` under `Modules/`, walks `getProperties(ReflectionProperty::IS_PUBLIC)` + the `$listeners` / `$queryString` arrays and fails if any references a column from `SecretsColumnRegistry::columns()`.

This is the only NEW pattern in Phase 17 with no clean in-repo analog. Recommend writing it as a Pest `it(...)` test (not the `arch()` plugin shape) since the check is reflection-based, not namespace-based.

---

### `Modules/Core/Internal/Bootstrap/EnsureAppKey.php` (service, file-I/O sentinel)

**Analog:** `Modules/Desktop/Internal/Native/FirstLaunchBootstrap.php` (first-launch bootstrap, DI'd `UserDataPathService` + `DatabaseManager`, idempotent runner).

**DI shape** (lines 32-38 of analog):

```php
final class EnsureAppKey
{
    public function __construct(
        private readonly UserDataPathService $paths,
        private readonly ConfigRepository $config,
        // injected Artisan kernel — or a thin wrapper that calls `key:generate`
    ) {}

    public function run(): void
    {
        $sentinel = $this->paths->appPath('first-launch.app-key-generated');
        if (file_exists($sentinel)) {
            return;  // already keyed
        }
        // generate APP_KEY via injected Artisan kernel
        // touch sentinel
    }
}
```

Hook from the existing `FirstLaunchBootstrap` — add a call to `$ensureAppKey->run()` after `runPendingMigrations()` in the bootstrap's entry method (project convention: chained one-shot bootstraps, each idempotent).

---

### `Modules/Core/Resources/views/livewire/help/data-locations.blade.php` + `HelpDataLocations.php` (component + view)

**Analogs:**
- Component: `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` (method-param DI on `render`)
- View: `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` (Tailwind-literal-class strings, escape-all-output, role/aria attributes, no `{!! !!}` raw output).

Inject `UserDataPathService` into `render()`, pass resolved paths into the view, render via `{{ ... }}` (escaped).

---

### `Modules/Counterparties/Internal/Listeners/InitializeCounterpartySeedOnInstall.php` (listener, event-driven — OPTIONAL)

**Analog:** `Modules/Onboarding/Internal/Listeners/InitializeWizardProgressOnInstall.php`

Per CONTEXT D-43..D-48 + A-04, none are planned at v1.0.0 — counterparties are derived from imports, not seeded per-user. Skip this listener unless the planner decides to seed a "self_account" placeholder for each of the user's own accounts at install time.

---

### `.github/workflows/release.yml` (config, event-driven)

**Analog:** `.github/workflows/ci.yml` (header comment lines 1-12 explicitly say "Phase 17 will extend the `php` axis below to `['8.4', '8.5']` and add the build / release / signing jobs").

**Reuse from analog:**
- `on:` trigger shape (analog has `pull_request` + `push: branches: [main]`; release.yml uses `push: tags: ['v*']`)
- `permissions:` block (analog has `contents: read`; release.yml needs `contents: write` for `softprops/action-gh-release`)
- `quality:` job — copy ci.yml's `quality` job verbatim as `gate:` in release.yml (D-12 reuse pattern — fail-fast on broken main)
- Matrix shape `php: ['8.4', '8.5']`
- `TZ: Europe/Amsterdam` env
- Same extensions list (line 55) and same Composer cache key shape (lines 59-65)

**New shape (no in-repo analog — pull from RESEARCH.md Pattern 2 lines 583-654):**
- Three platform jobs (`build-macos` / `build-windows` / `build-linux`) with `needs: gate`
- `publish:` job with `needs: [build-macos, build-windows, build-linux]` (D-11 all-must-succeed)
- SHA-pinned third-party actions (RESEARCH.md Pattern 1)
- macOS step: existing `scripts/nativephp_force_adhoc_signing.php` runs as part of the standard NativePHP prebuild chain (no new hook per A-01)
- Per-platform smoke test: install → `xattr -d com.apple.quarantine` on macOS only → launch → `curl /health` → exit (D-13 + D-15)
- Ed25519 manifest signing step on `publish:` job (sodium-based; signing-key in repo secret per UPDATE-02 + A-06)

---

### `LICENSE`, `NOTICE.md`, `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `README.md`

**Analogs:**
- LICENSE: external — verbatim from `firstdonoharm.dev` (CONTEXT canonical_refs)
- CODE_OF_CONDUCT.md: external — verbatim from `contributor-covenant.org/version/2/1/code_of_conduct/`
- CONTRIBUTING.md: source-material is `CLAUDE.md` (DI rule, arch tests, module Public/Internal split, Pint + Pest + Larastan L10 gate) — paraphrase the relevant sections without leaking GSD vocabulary
- README.md: REWRITE existing file; install-bypass section copy is verbatim in `17-UI-SPEC.md` lines 282-348 (`README install-bypass copy`)
- NOTICE.md: net-new prose; no analog — see Hippocratic-3.0 SPDX caveat in RESEARCH.md REL-01

**`composer.json` modification:** add `"license": "Hippocratic-3.0"`. If composer's SPDX validator rejects, fall back to per RESEARCH REL-01: pair with `"license-validation": false` OR use `"proprietary"` SPDX + explicit NOTICE.md attribution. Planner picks at PR time.

---

### `.docs/` tree

External mirror — `https://github.com/happklaar/happklaar` per CONTEXT D-31, D-32. No in-repo pattern. `.docs/cicd/branch-protection.md` and `.docs/runbooks/repo-security-setup.md` are already stub-extant; rewrite their contents during Plan 17-15's interactive walkthrough. ADRs are net-new prose graduated from `.planning/PROJECT.md` "Key Decisions" + the load-bearing v0.x decisions accumulated in PROJECT.md / phase CONTEXT files.

---

## Shared Patterns

### Constructor DI (project rule — load-bearing for every new class)

**Source:** `CLAUDE.md` memory `feedback_laravel_di_only` + `tests/Contracts/BoundaryArchTest.php` lines 79-136 + 957-1059 (`noFacadeCallsFromCoreConsoleCommands` + `noLaravelGlobalHelpersInCoreConsoleCommands`).

**Apply to:** Every new class file in Phase 17 — controllers, services, jobs, listeners, resolvers, GC job, registry, bootstrap. Two carve-outs (both pre-existing, no new entries needed):
1. Livewire `Component` subclasses MUST use method-parameter DI on `render()` and each wire action — `phpstan-strict-rules` bans constructor DI on Component (see `SystemAlertsBanner.php` lines 28-32 phpdoc).
2. Routes/web.php files keep `Illuminate\Support\Facades\Route` static DSL (arch-test carve-out documented in `BoundaryArchTest.php` lines 65-78).

**Excerpt** (`Modules/Core/Public/Services/SystemAlertQuery.php` lines 37-41):

```php
final readonly class SystemAlertQuery
{
    public function __construct(
        private DatabaseManager $db,
    ) {}
}
```

### BelongsToUser global scope (every user-scoped model)

**Source:** `Modules/Core/Public/Concerns/BelongsToUser.php`

**Apply to:** `Modules/Counterparties/Models/Counterparty.php` (the only new user-scoped model in Phase 17).

**Excerpt** (analog `Modules/Onboarding/Models/WizardProgress.php` lines 42-47):

```php
final class Counterparty extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'counterparties';
```

Companion phpdoc — copy verbatim from `WizardProgress.php` lines 21-31 — re-states the cross-user posture: explicit `where('user_id', ...)` is the primary guard; the global scope is secondary defence-in-depth.

### Module migration with status-enum + paired triggers

**Source:** `Modules/Onboarding/Database/Migrations/2026_05_26_000001_create_wizard_progress_table.php` + `Modules/Categorization/Database/Migrations/2026_05_17_010003_create_categorization_rules_table.php`

**Apply to:** `counterparties` table migration (the new bounded module's primary migration); the `type` column has a closed enum (`merchant|personal|bank|government|self_account|unknown`) and follows the same `BEFORE INSERT / BEFORE UPDATE OF type` trigger pattern.

**Excerpt** (`categorization_rules` migration lines 66-92):

```php
$allowedFields = "'merchant','description','counterparty'";
$connection->statement(sprintf(
    "CREATE TRIGGER counterparties_type_check_insert BEFORE INSERT ON counterparties FOR EACH ROW
     WHEN NEW.type NOT IN (%s)
     BEGIN SELECT RAISE(ABORT, 'Invalid counterparties.type value'); END",
    $allowedTypes,
));
```

### Module ServiceProvider boot pattern

**Source:** `Modules/Onboarding/Providers/OnboardingServiceProvider.php`

**Apply to:** `Modules/Counterparties/Providers/CounterpartiesServiceProvider.php`

**Excerpt** (lines 44-79 of analog):

```php
public function register(): void
{
    $this->app->singleton(/* Public contract */, /* Internal impl */);
}

public function boot(Dispatcher $events, LivewireManager $livewire): void
{
    $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
    $this->loadViewsFrom(__DIR__.'/../Resources/views', 'counterparties');

    $livewire->component('counterparties.index', CounterpartyIndex::class);
    // …
}
```

### Cross-user safety on raw query builder

**Source:** `Modules/Import/Internal/Services/KnownCounterpartyIbanResolver.php` lines 42-46 + `Modules/Import/Public/Services/MerchantNameResolver.php` lines 81-85.

**Apply to:** Every new `Modules/Counterparties/` service that reads from `counterparties` or `transactions`. The BelongsToUser global scope is secondary; the explicit `->where('user_id', $userId)` is primary.

**Excerpt:**

```php
$alias = $this->db->connection()
    ->table('counterparties')
    ->where('user_id', $userId)
    ->where('slug', $slug)
    ->value('id');
```

### Module-boundary arch invariant (auto-added per new module)

**Source:** `tests/Contracts/BoundaryArchTest.php` lines 9-63 — enumerates every module's `Internal` namespace as `toOnlyBeUsedIn('Modules\\<Name>')`.

**Apply to:** Add ONE line:

```php
arch('Modules\\Counterparties\\Internal is only used inside Modules\\Counterparties')
    ->expect('Modules\\Counterparties\\Internal')
    ->toOnlyBeUsedIn('Modules\\Counterparties');
```

### Pest test scaffolding (Unit + Feature)

**Source:** `Modules/Onboarding/tests/Unit/ResumeStepResolverTest.php` lines 1-44

**Apply to:** Every new test under `Modules/Counterparties/tests/`.

**Excerpt:**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Resolver\CounterpartyResolverService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('resolves a merchant counterparty via MerchantNameResolver bridge', function (): void {
    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);

    // … arrange + assert
});
```

### Livewire component conventions (NO constructor DI)

**Source:** `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` lines 28-52

**Apply to:** `CounterpartyIndex`, `CounterpartyProfile`, `CounterpartyTriage`, `HelpDataLocations`.

**Key constraints:**
1. Method-parameter DI on `render()` and every wire action (`acknowledge`, `skipVersion`, etc.).
2. Use `ViewFactory $views` + `$views->make('namespace::livewire.foo', [...])` — never `view(...)` helper (banned by `noLaravelGlobalHelpersInCoreConsoleCommands` — same posture applies for module code per DI-only memory).
3. Public properties exposed in the Livewire snapshot MUST NOT reference any `SecretsColumnRegistry::columns()` entry (D-29 arch invariant once landed).

### Blade view rendering conventions

**Source:** `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php` lines 1-19

**Apply to:** Every new Blade view in Phase 17.

**Rules:**
1. Every interpolation uses Blade `{{ }}` default escaping. Unescaped output `{!! !!}` is forbidden.
2. Tailwind classes are direct string literals — Tailwind's scanner doesn't follow `border-{tier}-500` interpolation.
3. `role=`, `aria-label=`, `wire:loading.attr=`, `focus-visible:ring-*` patterns are project-standard — pulled from analog.

### SHA-pinned third-party GitHub Actions

**Source:** RESEARCH.md Pattern 1 lines 560-581 (no in-repo example yet — ci.yml currently uses `@v4` / `@v2` tag pins; release.yml is the file that introduces the SHA-pin rule).

**Apply to:** Every third-party action in BOTH ci.yml (modify to add SHA pins) AND release.yml (NEW with SHA pins from day 1).

**Excerpt:**

```yaml
- uses: shivammathur/setup-php@7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc  # v2.37.1
  with:
    php-version: ${{ matrix.php }}
```

---

## No Analog Found

Files with no close match in the codebase (planner should use RESEARCH.md
patterns or external sources cited in CONTEXT.md canonical_refs):

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `.github/workflows/release.yml` (NEW workflow — full shape) | config (CI) | event-driven | ci.yml is shell-skeleton only; the tag-trigger + 3-platform-matrix + publish-gate + Ed25519 signing shape is net-new — pull from RESEARCH.md Pattern 2 |
| `.github/CODEOWNERS` (NEW) | config | static-config | GitHub convention; no in-repo precedent |
| `.env.bundled` (NEW) | config (template) | static-config | No `.env.example` template in repo currently |
| `Modules/Counterparties/Resources/views/livewire/counterparty-triage.blade.php` (focused-queue keyboard-driven page) | view | request-response | Closest analog is `Modules/Community/Resources/views/livewire/mystery-merchants-page.blade.php` but the focused-card-with-Y/N/S/→ keyboard pattern is NEW. UI-SPEC `<Interaction Contracts>` section is the spec; no internal Blade analog. |
| `tests/Contracts/SecretsInLivewireSnapshotTest.php` | test (arch) | batch (reflection) | Reflection-driven Livewire component walk is NEW; no in-repo analog. BoundaryArchTest's recursive directory walk + grep is the closest infrastructure shape. |
| `LICENSE`, `NOTICE.md`, `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md` | docs | static | External sources cited in CONTEXT.md canonical_refs (firstdonoharm.dev, contributor-covenant.org). CLAUDE.md is the source-material for CONTRIBUTING.md's DI/arch-test posture. |
| `.docs/` tree (entire) | docs | static | External mirror — `https://github.com/happklaar/happklaar` (CONTEXT D-31 + D-32). `.docs/cicd/branch-protection.md` and `.docs/runbooks/repo-security-setup.md` are already stub-extant but currently empty — rewrite contents from scratch during Plan 17-15 + 17-08. |
| `.docs/legal/license-rationale.md` + `data-retention.md` | docs | static | Net-new prose; no analog |
| `Modules/Core/Public/Controllers/HealthController.php` namespace path | controller | request-response | The project's other controllers live under `Internal/Http/Controllers/` — there is currently NO `Modules/<Name>/Public/Controllers/` directory in the repo. Planner decides whether to put HealthController under Public (per RESEARCH.md component map line 392) or under Internal/Http alongside the other controllers. Recommended: Public/Controllers/ because the route is genuinely a public, auth-free contract used by external smoke tests. |
| Ed25519 signing step (CI side) + verification step (PHP side) | service + workflow | one-shot crypto | No existing crypto-signing path in the project. Use PHP's bundled `sodium_*` (PHP 8.4+) — no new composer dependency required. |

---

## Metadata

**Analog search scope:**

- `Modules/` — full recursive scan for Public/Internal split, Models, Services, Resolvers, Migrations, Routes, Livewire components, Listeners, Jobs, Providers
- `.github/workflows/` — single existing file (ci.yml)
- `scripts/` — existing prebuild hooks
- `tests/Contracts/` — arch invariants (BoundaryArchTest is the master analog)
- `config/nativephp.php` — existing prebuild registration shape
- `.docs/` — partial stubs already present (branch-protection.md, repo-security-setup.md)

**Files scanned:** ~70 PHP/YAML/Blade files read directly; ~250 paths enumerated via `find` to confirm classification (no read needed for those).

**Pattern extraction date:** 2026-05-27

**Counterparty resolver dependency note (A-08):** Plan 17-06 (`Modules/Counterparties/`) DEPENDS on `Modules/Import/Internal/Services/KnownCounterpartyIbanResolver.php` + `Modules/Import/Models/KnownCounterpartyIban.php` already being landed by the parallel Phase 16.1.2.1 session. Both files exist and are read-mapped in this PATTERNS.md — they are mature analogs to copy from, AND they are the load-bearing collaborators step 2 of the 7-step resolution chain delegates to via DI. The dependency is satisfied by the time Plan 17-06 runs IF Phase 16.1.2.1 lands first.

**Sketch-findings skill:** the skill is already renamed at the project level (`./.claude/skills/sketch-findings-beatrax/`). The auto-loaded user-level rename (D-40 step 1) is still pending and is out-of-repo work (no PATTERNS-mappable analog).
