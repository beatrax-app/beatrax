# Phase 12: Multi-User Activation - Pattern Map

**Mapped:** 2026-05-19
**Files analyzed:** 42 new/modified files (across action, model, migration, livewire, middleware, service-provider, repository, arch-test, feature-test, unit-test, CLI command, view roles)
**Analogs found:** 40 strong analogs / 42 files (2 files: no direct analog — first-of-kind in repo)

This map exists to let the planner reference real, existing diederik conventions when writing PLAN.md files. Excerpts below are verbatim from the codebase with file path + line range so plans can say "copy lines 47-72 of `Modules/EmailScan/Providers/EmailScanServiceProvider.php`" instead of "use the singleton-binding pattern."

---

## File Classification

| New / Modified File | Role | Data Flow | Closest Analog | Match |
|--------------------|------|-----------|----------------|-------|
| `Modules/Auth/module.json` | module-manifest | n/a | `Modules/Categorization/module.json` | exact |
| `Modules/Auth/Providers/AuthServiceProvider.php` | service-provider | DI wiring | `Modules/Categorization/Providers/CategorizationServiceProvider.php` | exact |
| `Modules/Auth/Internal/Fortify/FortifyServiceProvider.php` | service-provider | Fortify config | `Modules/Core/Internal/Providers/FortifyServiceProvider.php` (MOVED+REWRITTEN from here) | exact |
| `Modules/Auth/Public/Actions/LoginAction.php` | action | request-response (mutates session) | `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` + research §Pattern 6 | role-match |
| `Modules/Auth/Public/Actions/SignupAction.php` | action | request-response (creates User + recovery codes in transaction) | `Modules/Categorization/Public/Actions/CreateCategorizationRule.php` | exact |
| `Modules/Auth/Public/Actions/LogoutAction.php` | action | request-response | research §Pattern 6 — see Allow-list note | role-match |
| `Modules/Auth/Public/Actions/ResetPasswordAction.php` | action | mutates User + UserRecoveryCode | `Modules/Categorization/Public/Actions/CreateCategorizationRule.php` | exact |
| `Modules/Auth/Public/Actions/RegenerateRecoveryCodesAction.php` | action | replaces 10 rows in user_recovery_codes (transaction) | `Modules/Categorization/Public/Actions/CreateCategorizationRule.php` | exact |
| `Modules/Auth/Public/Actions/ImpersonateUserAction.php` | action | session pivot via AuthManager + SessionContract | research §Pattern 6 (no exact codebase analog — first impersonation) | partial |
| `Modules/Auth/Public/Actions/EndImpersonationAction.php` | action | session unwind | research §Pattern 6 (paired with ImpersonateUserAction) | partial |
| `Modules/Auth/Public/Actions/AddUserAction.php` | action | creates partner User row | `Modules/Categorization/Public/Actions/CreateCategorizationRule.php` | exact |
| `Modules/Auth/Internal/Fortify/Authenticator.php` | service | Hasher check inside Fortify closure | `Modules/Core/Internal/Providers/FortifyServiceProvider.php` lines 37-53 | exact |
| `Modules/Auth/Internal/Recovery/RecoveryCodeGenerator.php` | service (stateless) | pure-function string output | no direct analog — first random-string generator | none |
| `Modules/Auth/Internal/Recovery/RecoveryCodeAuthenticator.php` | service | DB read + write + system_alerts emit | `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` | role-match |
| `Modules/Auth/Internal/Recovery/RecoveryCodeFormatter.php` | service (stateless) | string→file-bytes transform | no direct analog — first `.txt` builder | none |
| `Modules/Auth/Internal/Http/Livewire/LoginPage.php` | livewire-page | form submit → action → redirect | `Modules/Categorization/Internal/Http/Livewire/RulesPage.php` | exact |
| `Modules/Auth/Internal/Http/Livewire/SignupPage.php` | livewire-page | same as LoginPage + redirects to recovery-codes display | `Modules/Categorization/Internal/Http/Livewire/RulesPage.php` | exact |
| `Modules/Auth/Internal/Http/Livewire/RecoveryCodesDisplay.php` | livewire-page | renders codes + download response | `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php` (form + action pattern) | role-match |
| `Modules/Auth/Internal/Http/Livewire/ChangePasswordPage.php` | livewire-page | form submit → action → redirect | `Modules/Categorization/Internal/Http/Livewire/RulesPage.php` | exact |
| `Modules/Auth/Internal/Http/Livewire/ResetPasswordPage.php` | livewire-page | form submit → action → redirect | `Modules/Categorization/Internal/Http/Livewire/RulesPage.php` | exact |
| `Modules/Auth/Internal/Http/Livewire/ManageUserPage.php` | livewire-page | renders partner profile + opens modals | `Modules/Categorization/Internal/Http/Livewire/RulesPage.php` | exact |
| `Modules/Auth/Internal/Http/Livewire/AddUserPage.php` | livewire-page | form submit → AddUserAction | `Modules/Categorization/Internal/Http/Livewire/RulesPage.php` | exact |
| `Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php` | middleware | reads session, shares view variable | `Modules/Core/Internal/Http/Middleware/LoopbackOnly.php` (handle() shape) | partial |
| `Modules/Auth/Internal/Http/Middleware/ForcePasswordChangeMiddleware.php` | middleware | redirects on flag | `Modules/Core/Internal/Http/Middleware/LoopbackOnly.php` | role-match |
| `Modules/Auth/Internal/Console/ResetPasswordCommand.php` | cli-command | interactive `secret()` prompt | `Modules/Core/Internal/Console/InstallCommand.php` lines 140-141, 204-213 | exact |
| `Modules/Auth/Internal/Console/RegenerateRecoveryCodesCommand.php` | cli-command | interactive prompt + table output | `Modules/Core/Internal/Console/InstallCommand.php` | exact |
| `Modules/Auth/Internal/Console/GrantDeveloperCommand.php` | cli-command | interactive confirm | `Modules/Core/Internal/Console/InstallCommand.php` | exact |
| `Modules/Auth/Internal/Listeners/EmitOAuthReauthRequiredAlert.php` | listener | inserts system_alerts row | `Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php` (registered via `$events->listen()` in provider, line 74) | role-match |
| `Modules/Auth/Models/UserRecoveryCode.php` | model | Eloquent + BelongsToUser | `Modules/Categorization/Models/CategorizationRule.php` | exact |
| `Modules/Auth/Database/Migrations/2026_05_19_000001_drop_email_add_username_to_users_table.php` | migration | ALTER TABLE drop+add | `Modules/Recurring/Database/Migrations/2026_05_18_010004_add_recurring_settings_to_users.php` | exact |
| `Modules/Auth/Database/Migrations/2026_05_19_000002_add_is_developer_to_users_table.php` | migration | ALTER TABLE add column | `Modules/Recurring/Database/Migrations/2026_05_18_010004_add_recurring_settings_to_users.php` | exact |
| `Modules/Auth/Database/Migrations/2026_05_19_000003_add_force_password_change_to_users_table.php` | migration | ALTER TABLE add column | `Modules/Recurring/Database/Migrations/2026_05_18_010004_add_recurring_settings_to_users.php` | exact |
| `Modules/Auth/Database/Migrations/2026_05_19_000004_create_user_recovery_codes_table.php` | migration | CREATE TABLE | `Modules/EmailScan/Database/Migrations/2026_05_16_020001_create_inboxes_table.php` | exact |
| `Modules/Auth/Database/Migrations/2026_05_19_000005_create_oauth_secrets_table.php` | migration | CREATE TABLE + encrypted-cast columns | `Modules/EmailScan/Database/Migrations/2026_05_16_020001_create_inboxes_table.php` | exact |
| `Modules/Auth/Database/Migrations/2026_05_19_000006_migrate_legacy_email_oauth_json.php` | migration | filesystem rename via Filesystem | `Modules/Core/Internal/Console/InstallCommand.php` lines 86-90 (Filesystem DI) | role-match |
| `Modules/Auth/Routes/web.php` | route-file | Volt-page-renders + impersonation banner middleware group | `Modules/Categorization/Routes/web.php` + `Modules/Core/Routes/web.php` | exact |
| `Modules/Auth/Resources/views/partials/impersonation-banner.blade.php` | view-partial | renders amber banner from session | `Modules/Core/Resources/views/auth/login.blade.php` (layout shape) | partial |
| `Modules/Auth/Resources/views/livewire/*.blade.php` (7 SFC templates) | livewire-view | bound to Livewire class via render() | `Modules/Core/Resources/views/auth/login-form.blade.php` (form chrome) | exact |
| `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` | service (REWRITTEN) | SQLite-backed CRUD with CurrentUser DI | self (rewrite: replace Filesystem with DatabaseManager + CurrentUser) | exact |
| `Modules/EmailScan/Models/OAuthSecret.php` | model | Eloquent + encrypted casts + BelongsToUser | `Modules/Categorization/Models/CategorizationRule.php` + research §Pattern 5 | role-match |
| `Modules/EmailScan/Providers/EmailScanServiceProvider.php` (MODIFIED) | service-provider | rebind `OAuthSecretsRepository` with new constructor deps | self (existing line 76: `$this->app->singleton(OAuthSecretsRepository::class);` stays, but now the class resolves with `CurrentUser + DatabaseManager + Hasher` instead of `Filesystem`) | exact |
| `tests/Contracts/BoundaryArchTest.php` (APPENDED) | arch-test | recursive grep of forbidden symbols with allow-list | self lines 926-1028 (`noFacadeCallsFromCoreConsoleCommands` + `noLaravelGlobalHelpersInCoreConsoleCommands`) | exact |
| `Modules/Auth/tests/Feature/CrossUserIsolationTest.php` | feature-test | Pest dataset iterating routes | `Modules/Core/tests/Unit/SystemAlertsMigrationTest.php` lines 92-114 (`->with([...])`) + research §Pattern 8 | partial |
| `Modules/Auth/tests/Feature/*Test.php` (LoginPage, SignupPage, ResetPassword, ChangePassword, RecoveryCodes, ImpersonationAction, ConsoleCommands) | feature-test | acting-as + Livewire::test | `Modules/Categorization/tests/Feature/AssignCategoryTest.php` | exact |
| `Modules/Auth/tests/Unit/*Test.php` (RecoveryCodeGenerator, RecoveryCodeAuthenticator, UsernameNormalizer, ForcePasswordChangeMiddleware) | unit-test | direct class instantiation | `Modules/Core/tests/Unit/SystemAlertsMigrationTest.php` | role-match |

---

## Pattern Assignments

### `Modules/Auth/module.json` (module-manifest)

**Analog:** `Modules/Categorization/module.json`

**Full file template** — copy verbatim, change name/alias/description/priority:

```json
{
    "name": "Categorization",
    "alias": "categorization",
    "description": "Category tree, merchant memory schema, manual categorization actions and triage inbox.",
    "keywords": ["categorization", "merchants", "triage"],
    "priority": 4,
    "providers": [
        "Modules\\Categorization\\Providers\\CategorizationServiceProvider"
    ],
    "files": []
}
```

**Priority guidance:** `Modules/Core` has priority 0 (lowest = loads first per `tests/Contracts/ModulePrioritiesArchTest.php`). Auth must load AFTER Core (it depends on `Modules\Core\Models\User`, `BelongsToUser`, `CurrentUser`) — pick priority `1` so it boots immediately after Core. The `ModulePrioritiesArchTest` only enforces `Core < other`; any priority `>= 1` satisfies it.

---

### `Modules/Auth/Providers/AuthServiceProvider.php` (service-provider, DI wiring)

**Analog:** `Modules/Categorization/Providers/CategorizationServiceProvider.php` (full file at `/Users/wesselverheij/Development/diederik/Modules/Categorization/Providers/CategorizationServiceProvider.php`)

**Imports pattern** (lines 1-32):
```php
<?php

declare(strict_types=1);

namespace Modules\Categorization\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
// … (more Internal + Public imports)
use Modules\Core\Public\Events\UserInstalled;
```

**Register pattern** (lines 48-65):
```php
public function register(): void
{
    $this->app->bind(AssignsCategory::class, AssignCategory::class);
    $this->app->bind(AppliesAutoCategory::class, ApplyAutoCategoryStage::class);
    $this->app->singleton(UncategorizedTriageQuery::class);
    // … each Public action / Public service / Internal singleton-safe class registered
}
```

**Boot pattern** (lines 67-83):
```php
public function boot(Dispatcher $events, LivewireManager $livewire): void
{
    $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
    $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
    $this->loadViewsFrom(__DIR__.'/../Resources/views', 'categorization');

    $events->listen(UserInstalled::class, SeedDefaultCategoryTree::class);
    $events->listen(TransactionCategorized::class, [MerchantMemoryWriter::class, 'handle']);

    $livewire->component('categorization.rules-page', RulesPage::class);
    // … one $livewire->component() call per page/modal
}
```

**Notes for Auth:** Also register the new `Modules\Auth\Internal\Fortify\FortifyServiceProvider` via `$this->app->register(FortifyServiceProvider::class)` inside `register()` — see `CoreServiceProvider::register()` line 50 for the pattern (`$this->app->register(FortifyServiceProvider::class);`). The MOVED FortifyServiceProvider line in `CoreServiceProvider` must be deleted as part of this rewire.

---

### `Modules/Auth/Internal/Fortify/FortifyServiceProvider.php` (service-provider, Fortify config)

**Analog:** `Modules/Core/Internal/Providers/FortifyServiceProvider.php` (full file at `/Users/wesselverheij/Development/diederik/Modules/Core/Internal/Providers/FortifyServiceProvider.php`)

**Why this is exact:** Phase 12 MOVES this file from Core to Auth and rewrites the authenticator closure for `username` instead of `email`, and drops the rate-limiter per D-12. The boot() shape, the Fortify::loginView / Fortify::authenticateUsing pattern, and the constructor-DI'd `Hasher` parameter are kept verbatim.

**Existing closure that needs rewriting** (lines 33-53):
```php
public function boot(Hasher $hasher, RateLimiter $rateLimiter): void
{
    Fortify::loginView('core::auth.login');

    Fortify::authenticateUsing(static function (Request $request) use ($hasher): ?User {
        $email = $request->input('email');
        $password = $request->input('password');

        if (! is_string($email) || ! is_string($password)) {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User && $hasher->check($password, $user->password)) {
            return $user;
        }

        return null;
    });
    // …
}
```

**Phase 12 rewrite shape** (already in research §Pattern 2):
- Replace `email` field references with `username` + `strtolower(trim($value))` normalization (D-02).
- Drop the rate-limiter section (lines 56-63) per D-12.
- Add `Fortify::registerView()` + `Fortify::createUsersUsing(SignupAction::class)` calls (research §Pattern 2 lines 583-594).
- Wire `Fortify::authenticateThrough()` per research §Pattern 2 to drop `EnsureLoginIsNotThrottled`.

**Class-level docblock** (lines 16-29) shows the project's tone for explaining "why this design" — copy the structure but rewrite for username-based auth.

**This file IS on the `noAuthFacadeOrHelper` allow-list** (D-24) — see arch-test pattern below.

---

### `Modules/Auth/Public/Actions/SignupAction.php` (action, request-response, CRUD)

**Analog:** `Modules/Categorization/Public/Actions/CreateCategorizationRule.php` (full file at `/Users/wesselverheij/Development/diederik/Modules/Categorization/Public/Actions/CreateCategorizationRule.php`)

**Imports + class-level docblock** (lines 1-49):
```php
<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Public action that inserts one categorization_rules row scoped to
 * the supplied user. The action is the sole permissible write path
 * from the UI / API into categorization_rules — the model itself is
 * fillable but every call site routes through this action so the
 * field/match whitelist + duplicate-translation logic stays in one
 * place.
 *
 * Validation contract:
 *   - `$field` MUST be one of `merchant` / `description` / `counterparty`.
 *   …
 *
 * Duplicate-rule mitigation: the (user_id, field, match, value)
 * UNIQUE constraint on the table rejects a second identical rule.
 * The action catches the resulting QueryException and translates it
 * into a Laravel ValidationException so the Livewire form modal can
 * render the locked copy under the offending field.
 */
final class CreateCategorizationRule
{
```

**Constructor DI** (lines 58-61):
```php
public function __construct(
    private readonly DatabaseManager $db,
    private readonly Clock $clock,
) {}
```

**Invocation method + validation + transaction-like insert + unique-violation translation** (lines 63-108):
```php
public function __invoke(User $user, string $field, string $match, string $value, int $categoryId): int
{
    if (! in_array($field, self::VALID_FIELDS, true)) {
        throw new InvalidArgumentException(
            "CreateCategorizationRule: invalid field '{$field}'."
        );
    }
    // … (more validation)

    $now = $this->clock->now()->toDateTimeString();

    try {
        return $this->db->connection()
            ->table('categorization_rules')
            ->insertGetId([
                'user_id' => $user->id,
                // …
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    } catch (QueryException $e) {
        if (self::isUniqueViolation($e)) {
            throw ValidationException::withMessages([
                'value' => self::DUPLICATE_MESSAGE,
            ]);
        }
        throw $e;
    }
}
```

**Apply to SignupAction:** Wrap the User row insert + 10 recovery code inserts in a single `$this->db->connection()->transaction(...)` so the `User::count() === 0 → is_developer = true` check is race-free (D-04). Mirror the `final class`, the constructor-readonly DI, the explicit `InvalidArgumentException` on validation failure, and the `ValidationException::withMessages()` translation pattern. Username uniqueness violation → `ValidationException` keyed to `'username'`.

**This file IS on the `noAuthFacadeOrHelper` allow-list** (D-24) — it's allowed to call `Auth::loginUsingId()` after the row is created (the signup flow auto-logs-in the first user).

---

### `Modules/Auth/Public/Actions/AddUserAction.php`, `ResetPasswordAction.php`, `RegenerateRecoveryCodesAction.php`, `LoginAction.php`, `LogoutAction.php` (action, CRUD)

**Analog (all five):** `Modules/Categorization/Public/Actions/CreateCategorizationRule.php` (same shape as SignupAction)

**Apply pattern from above** — `final class`, constructor `readonly` DI, `__invoke(User $caller, …)` signature, validation throws `InvalidArgumentException`, business-rule conflicts throw `ValidationException::withMessages()`, mutations go through `DatabaseManager::connection()->transaction()` when multi-row.

**All five files ARE on the `noAuthFacadeOrHelper` allow-list** (D-24).

**Cross-user safety pattern** — copy from `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` lines 52-65:
```php
public function __invoke(int $alertId, User $user): SystemAlert
{
    $userId = $user->id;

    $row = $this->db->connection()->table('system_alerts')
        ->where('id', $alertId)
        ->where(function (Builder $q) use ($userId): void {
            $q->where('user_id', $userId)->orWhereNull('user_id');
        })
        ->first();

    if ($row === null) {
        throw new NotFoundHttpException('System alert not found.');
    }
```

ResetPasswordAction + RegenerateRecoveryCodesAction look up the target user by username — wrap the lookup with `where('username', $normalized)` and throw `NotFoundHttpException` on miss so cross-user probes get 404, not 403 (D-25 invariant applies to actions too).

---

### `Modules/Auth/Public/Actions/ImpersonateUserAction.php` + `EndImpersonationAction.php` (action, session pivot)

**Analog:** No direct codebase analog (this is the first session-pivot action). Closest existing analog by shape is `Modules/Categorization/Public/Actions/CreateCategorizationRule.php` (constructor-DI'd action class with `__invoke`). See research §Pattern 6 (lines 822-877 of `12-RESEARCH.md`) for the full draft.

**Constructor DI shape to use** (from research):
```php
public function __construct(
    private AuthManager $auth,                        // Illuminate\Auth\AuthManager
    private Hasher $hasher,                           // Illuminate\Contracts\Hashing\Hasher
    private SessionContract $session,                 // Illuminate\Contracts\Session\Session
    private CurrentUser $currentUser,                 // Modules\Core\Public\Contracts\CurrentUser
) {}
```

**Why each contract is OK under DI-only rule:**
- `AuthManager` is the framework class behind the `Auth::` facade — it's the legitimate DI surface (research §Pattern 6 explanation).
- `Illuminate\Contracts\Session\Session` is a contract, NOT a facade — the `noAuthFacadeOrHelper` regex matches `session(` (helper) and `request()->session(`, not the contract import.
- The grep MUST NOT match this contract — the regex `'/\\bsession\\s*\\(/'` is anchored to the helper-call surface only.

**These two files ARE on the `noAuthFacadeOrHelper` allow-list** (D-24) — they invoke `$this->auth->guard()->loginUsingId()`.

**Result type:** Return a small DTO class (`ImpersonationResult` with static factories `success() / wrongPassword() / notAllowed() / invalidTarget()`) instead of throwing — the caller is a Phase 16 Livewire modal that wants to render error copy, not an exception page. Pattern shape: copy the static-factory pattern from any of the Forecasting `ScenarioMutation` DTOs (Modules/Forecasting/Models/ForecastScenarioMutation.php) or the `OAuthExchangeFailed` class shape.

---

### `Modules/Auth/Internal/Recovery/RecoveryCodeAuthenticator.php` (service)

**Analog:** `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` (transaction + cross-user-safe lookup) — full draft already in research §Pattern 4 lines 672-737.

**Transaction-with-lockForUpdate pattern** (research §Pattern 4):
```php
return $this->db->transaction(function () use ($username, $normalized): ?User {
    $user = User::query()->where('username', $username)->first();
    if (! $user instanceof User) {
        $this->emit('auth.recovery_code_failed', 'error', $usernameInput, null);
        return null;
    }

    $unusedCodes = UserRecoveryCode::query()
        ->where('user_id', $user->id)
        ->whereNull('used_at')
        ->lockForUpdate()
        ->get();

    foreach ($unusedCodes as $code) {
        if ($this->hasher->check($normalized->plaintext(), $code->code_hash)) {
            $code->forceFill(['used_at' => $this->clock->now()])->save();
            // …
            return $user;
        }
    }
    // …
});
```

**Hasher injection** — verbatim from `Modules/Core/Internal/Providers/FortifyServiceProvider.php` line 33: `public function boot(Hasher $hasher, …)`. The same `Illuminate\Contracts\Hashing\Hasher` is the legitimate DI surface; `Hash::check()` (facade) is forbidden by `noAuthFacadeOrHelper`.

**Clock injection** — verbatim from `Modules/Categorization/Public/Actions/CreateCategorizationRule.php` line 60: `private readonly Clock $clock`. `Clock` is `Modules\Core\Public\Contracts\Clock` — same DI seam, never `now()` helper.

**System alert emission** — see "Shared Patterns: System Alerts emission" below.

---

### `Modules/Auth/Internal/Recovery/RecoveryCodeGenerator.php` (service, stateless)

**Analog:** No direct codebase analog — first random-string generator in the repo.

**Pattern:** Full implementation already in research §Pattern 3 lines 626-651 (29 lines, `final class`, `random_int()`-based, returns formatted string). Apply project conventions:
- `final class`
- `declare(strict_types=1)`
- One PHPDoc on `generate()` explaining the output format
- Constant for alphabet (`private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';`)
- No DI needed — pure-function class

**Unit test:** Generate 1000 codes, assert each matches `/^[A-NP-Z2-9]{4}(-[A-NP-Z2-9]{4}){4}$/`. Use `Modules/Core/tests/Unit/SystemAlertsMigrationTest.php` lines 99-114 as the dataset-iteration shape (`->with([...])`).

---

### `Modules/Auth/Internal/Http/Livewire/LoginPage.php` (livewire-page, request-response)

**Analog:** `Modules/Categorization/Internal/Http/Livewire/RulesPage.php` (full file at `/Users/wesselverheij/Development/diederik/Modules/Categorization/Internal/Http/Livewire/RulesPage.php`)

**Class declaration + properties** (lines 1-43):
```php
<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
// …
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The `/rules` route landing — the categorization-rule CRUD page.
 *
 * Reads via CategorizationRuleQuery (user-scoped); mutates via the
 * three Public actions (Create/Update/Delete). The page itself owns
 * the table render + the per-row Edit / Delete chip flow; …
 *
 * Constructor-free Livewire component; service collaborators arrive
 * as parameters on action methods + render(). The strict-rules
 * ruleset forbids property-based constructor injection on Component
 * subclasses; `auth()` / `Auth::user()` / facade lookups are out of
 * bounds project-wide.
 */
final class RulesPage extends Component
{
    public ?int $confirmingDeleteId = null;

    public string $flashMessage = '';
```

**Action-method DI shape** (lines 64-83):
```php
public function deleteRule(int $ruleId, CurrentUser $currentUser, DeleteCategorizationRule $delete): void
{
    try {
        ($delete)($currentUser->user(), $ruleId);
    } catch (NotFoundHttpException) {
        $this->confirmingDeleteId = null;
        $this->flashMessage = 'Rule not found (it may have been deleted in another tab).';
        return;
    }
    $this->confirmingDeleteId = null;
    $this->flashMessage = 'Rule deleted.';
}
```

**Render method DI + extends('layouts.app')** (lines 96-108):
```php
public function render(CurrentUser $currentUser, CategorizationRuleQuery $query, ViewFactory $views): View
{
    $rules = $query->forUser($currentUser->user());

    $view = $views->make('categorization::livewire.rules-page', [
        'rules' => $rules,
    ]);

    /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
    $view->extends('layouts.app', ['title' => 'Rules · diederik']);

    return $view;
}
```

**Critical note** (line 36): "Constructor-free Livewire component; service collaborators arrive as parameters on action methods + render(). The strict-rules ruleset forbids property-based constructor injection on Component subclasses."

**Phase 12 difference from research §Pattern 1:** Research §Pattern 1 (lines 478-525) shows `#[Layout]` + `#[Title]` attribute-based layout binding. The diederik codebase uses **the older `$view->extends('layouts.app', ['title' => ...])` shape with the `@phpstan-ignore-next-line` comment** — match the codebase, not the research draft, so Phase 12 Livewire components blend in.

**Apply to:** All seven Livewire pages (LoginPage, SignupPage, RecoveryCodesDisplay, ChangePasswordPage, ResetPasswordPage, ManageUserPage, AddUserPage).

---

### `Modules/Auth/Internal/Http/Livewire/SignupPage.php` redirect-on-success

**Analog for redirect:** `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php` lines 75+

**Pattern from research §Pattern 1** (line 518): `return $this->redirect($urls->route('dashboard'), navigate: false);` — inject `UrlGenerator $urls` on the action method.

---

### `Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php` (middleware)

**Analog:** `Modules/Core/Internal/Http/Middleware/LoopbackOnly.php` (full file at `/Users/wesselverheij/Development/diederik/Modules/Core/Internal/Http/Middleware/LoopbackOnly.php`)

**Class shape** (lines 1-48):
```php
<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Refuses any request whose `SERVER_ADDR` is not a loopback address. …
 */
final class LoopbackOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $serverAddr = $request->server('SERVER_ADDR');

        if (is_string($serverAddr) && ! self::isLoopback($serverAddr)) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
```

**For ImpersonationBannerMiddleware:**
- `final readonly class ImpersonationBannerMiddleware` with constructor-DI for `Illuminate\Contracts\Session\Session $session` + `Illuminate\Contracts\View\Factory $views`.
- Inside `handle()`, after `$next($request)`, read `$this->session->get('auth.impersonating.original_user_id')` and `$this->session->get('auth.impersonating.original_username')`. If set, call `$this->views->share('impersonatingPartnerUsername', $username)` so the global `layouts/app.blade.php` can `@isset` it and render the banner.
- **This file IS on the `noAuthFacadeOrHelper` allow-list** (D-24) — it's the only Internal/Http/ middleware allowed to touch session().

**Alternative pattern (view composer)** — see `Modules/EmailScan/Providers/EmailScanServiceProvider.php` lines 147-162 (`$factory->composer('core::livewire.top-nav', static function (View $compose) use ($app): void {...})`) for the view-composer shape. The banner could be rendered by a composer registered on `layouts.app` instead of middleware, but D-23 explicitly specifies middleware — match the decision.

**`Resources/views/partials/impersonation-banner.blade.php`** — copy the calm-Linear card chrome from `Modules/Core/Resources/views/auth/login-form.blade.php` lines 1-50. Banner-specific styling (amber bg, no-dismiss) is locked by `12-UI-SPEC.md` §"Impersonation banner (paint contract)" lines 247-260.

---

### `Modules/Auth/Internal/Http/Middleware/ForcePasswordChangeMiddleware.php` (middleware)

**Analog:** `Modules/Core/Internal/Http/Middleware/LoopbackOnly.php` (same shape as above)

**Full implementation:** Already drafted in research §Pattern 5 lines 762-803. Key points:
- `final readonly class`, constructor-injected `CurrentUser` + `UrlGenerator`.
- `handle(Request $request, Closure $next): SymfonyResponse`.
- Whitelist via `private const ALLOWED_ROUTE_NAMES`.
- Returns `new \Illuminate\Http\RedirectResponse($this->urls->route('auth.change-password'))` on redirect.

**This file is NOT on the allow-list** — `CurrentUser` is the DI seam, never `Auth::user()`.

---

### `Modules/Auth/Internal/Console/ResetPasswordCommand.php` + `RegenerateRecoveryCodesCommand.php` + `GrantDeveloperCommand.php` (cli-command)

**Analog:** `Modules/Core/Internal/Console/InstallCommand.php` (full file at `/Users/wesselverheij/Development/diederik/Modules/Core/Internal/Console/InstallCommand.php`)

**Class declaration + signature + constructor DI** (lines 40-90):
```php
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'diederik:install
        {--email= : Email for the single-user account}
        {--password= : Password for the single-user account}
        // …

    /** @var string */
    protected $description = 'Idempotent first-run setup: …';

    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $events,
        private readonly DatabaseManager $db,
        private readonly Filesystem $files,
        private readonly Application $app,
    ) {
        parent::__construct();
    }
```

**Interactive `secret()` prompt** (lines 140-141, 204-213):
```php
$email = $this->resolveStringInput('email', 'Email');
$password = $this->resolveStringInput('password', 'Password', secret: true);
// …
private function resolveStringInput(string $option, string $prompt, bool $secret = false): string
{
    $value = $this->option($option);

    if (! is_string($value) || $value === '') {
        $value = $secret ? $this->secret($prompt) : $this->ask($prompt);
    }

    return is_string($value) ? $value : '';
}
```

**For Phase 12 CLI commands per D-14:** D-14 explicitly requires "no `--password=` style flag". Apply this by:
- Signature has ONE positional argument (`{username}`) and ZERO `--password` options.
- The interactive `$this->secret('New password')` is the only way to enter the password.
- Refuse non-interactive use: if `! $this->input->isInteractive()`, error out and return `self::FAILURE`.

**Registration** — see `CoreServiceProvider::boot()` lines 106-114:
```php
if ($this->app->runningInConsole()) {
    $this->commands([
        InstallCommand::class,
        // …
    ]);
}
```

**These CLI files are NOT on the `noAuthFacadeOrHelper` allow-list** — `CurrentUser` is not relevant in CLI; they look up the user via `User::query()->where('username', $username)->firstOrFail()`. They DO consume `Hasher` for password hashing — that's a contract, not a facade.

---

### `Modules/Auth/Models/UserRecoveryCode.php` (model)

**Analog:** `Modules/Categorization/Models/CategorizationRule.php` (full file at `/Users/wesselverheij/Development/diederik/Modules/Categorization/Models/CategorizationRule.php`)

**Full model shape** (lines 1-69):
```php
<?php

declare(strict_types=1);

namespace Modules\Categorization\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the categorization_rules table — user-defined
 * matchers that pre-categorise an incoming transaction at import time.
 *
 * The model uses BelongsToUser so global scope queries via Eloquent
 * automatically filter by the authenticated user. …
 *
 * @property int $id
 * @property int|null $user_id
 * // …
 */
final class CategorizationRule extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'categorization_rules';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'field',
        // …
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            // …
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
```

**Apply to UserRecoveryCode:**
- `final class UserRecoveryCode extends Model`
- `use BelongsToUser;`
- `protected $table = 'user_recovery_codes';`
- `$fillable = ['user_id', 'code_hash', 'used_at']`
- Casts: `'used_at' => 'immutable_datetime'`, `'created_at' => 'immutable_datetime'`
- D-07 — no `updated_at` column (audit-only mutation is the `used_at` stamp), so set `public $timestamps = false;` per `Modules/Core/Models/SystemAlert.php` line 68.

---

### `Modules/EmailScan/Models/OAuthSecret.php` (model with `encrypted` cast)

**Analog:** `Modules/Categorization/Models/CategorizationRule.php` + the `encrypted` cast is NEW to the codebase (no existing analog).

**Apply pattern from CategorizationRule.php** plus the new cast:
```php
protected function casts(): array
{
    return [
        'client_secret' => 'encrypted',          // D-17
        'tokens_blob'   => 'encrypted',          // D-17
        'created_at'    => 'immutable_datetime',
        'updated_at'    => 'immutable_datetime',
    ];
}
```

**Note for planner:** This is the first `encrypted` cast in the codebase. Test it explicitly with a Pest unit test that round-trips a value through Eloquent + queries the raw row with `DatabaseManager::table()` and asserts the ciphertext does NOT contain the plaintext.

**Fillable + BelongsToUser:** Use the trait + `$fillable = ['user_id', 'provider', 'client_id', 'client_secret', 'redirect_uri', 'tokens_blob']` per D-15.

---

### `Modules/Auth/Database/Migrations/*.php` (6 migrations)

**Analog for ALTER TABLE migrations (drop+add columns):** `Modules/Recurring/Database/Migrations/2026_05_18_010004_add_recurring_settings_to_users.php` (full file at the path)

**Full template** (lines 1-62):
```php
<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds two per-user preference columns that drive the recurring
 * detector … (PHPDoc describes WHAT, not WHY history)
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->unsignedSmallInteger('recurring_detection_window_months')
                ->default(18)
                ->after('auto_import_drop_folder');
            // …
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn(['recurring_detection_window_months', 'recurring_income_min_amount_minor']);
        });
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
```

**Pattern critical points:**
- Anonymous-class migration (`return new class extends Migration`).
- DatabaseManager resolved via `Container::getInstance()->make(DatabaseManager::class)` — Laravel migrations run before regular DI is wired so the container helper is the project's standard escape hatch (BoundaryArchTest carves out migrations implicitly because it scans `Modules/*/Internal` and not `Modules/*/Database/Migrations`).
- NEVER use the `Schema::` facade (`Illuminate\Support\Facades\Schema` is forbidden — see line 56 of `BoundaryArchTest.php`: "no Laravel facade usage in module code"). Use `$this->schema()->table()` / `$this->schema()->create()` instead.
- Class-level docblock describes WHAT the migration does today, never history (CLAUDE.md C-10 / user memory `feedback_docs_describe_current_state.md`).

**Analog for CREATE TABLE migrations:** `Modules/EmailScan/Database/Migrations/2026_05_16_020001_create_inboxes_table.php` (full file at the path)

**CREATE TABLE shape** (lines 31-65):
```php
public function up(): void
{
    $this->schema()->create('inboxes', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        $table->string('provider', 16);
        // …
        $table->timestamps();

        $table->index(['user_id', 'provider']);
        $table->index(['user_id', 'created_at']);
    });

    // SQLite trigger pair for enum-shaped string columns
    $connection = $this->db()->connection($this->getConnection());
    $allowedProviders = "'gmail','microsoft'";

    $connection->statement(sprintf(
        "CREATE TRIGGER inboxes_provider_check_insert BEFORE INSERT ON inboxes FOR EACH ROW
         WHEN NEW.provider NOT IN (%s)
         BEGIN SELECT RAISE(ABORT, 'Invalid inboxes.provider value'); END",
        $allowedProviders,
    ));
    // … same for UPDATE
}
```

**Apply to Phase 12 migrations:**

1. **`...drop_email_add_username_to_users_table.php`** — ALTER TABLE shape. Drop `email`, add `username` (unique). Use `$table->dropColumn('email')` then `$table->string('username')->unique()`. **CRITICAL:** SQLite cannot DROP and ADD in one ALTER TABLE block — Laravel's schema builder handles this via the doctrine-like reshape strategy automatically for SQLite, but verify the migration is atomic in the test harness. Consider adding a `LOWER(username)` unique index expression via raw SQL (D-02) instead of just `->unique()`.

2. **`...add_is_developer_to_users_table.php`** — `$table->boolean('is_developer')->default(false)->after('username')`.

3. **`...add_force_password_change_to_users_table.php`** — `$table->boolean('force_password_change_at_next_login')->default(false)`.

4. **`...create_user_recovery_codes_table.php`** — Apply EmailScan inboxes CREATE TABLE shape. Columns per D-07: `id, user_id (foreignId nullable constrained), code_hash (string), used_at (timestamp nullable), created_at`. Index on `user_id`. No `updated_at` (audit-only).

5. **`...create_oauth_secrets_table.php`** — Same CREATE TABLE shape. Columns per D-15: `id, user_id, provider (string 16), client_id (string), client_secret (text — encrypted at app layer), redirect_uri (string), tokens_blob (text — encrypted at app layer), timestamps`. Unique index on `(user_id, provider)`. Add the BEFORE INSERT/UPDATE trigger pair for `provider` enum check using `'gmail','microsoft'` (copy the EmailScan trigger pattern verbatim).

6. **`...migrate_legacy_email_oauth_json.php`** — Filesystem-only migration. Inject Filesystem via `Container::getInstance()->make(Filesystem::class)` inside `up()` (same pattern as `db()` helper above). Rename `storage/app/secrets/email-oauth.json` → `storage/app/secrets/email-oauth.json.pre-phase-12.bak` (chmod 0600) per D-19. Write the README at `storage/app/secrets/README.md` documenting the rename. `down()` is a NO-OP (one-way migration).

---

### `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` (REWRITTEN)

**Analog:** itself (self-rewrite from JSON to SQLite). Existing file at `/Users/wesselverheij/Development/diederik/Modules/EmailScan/Public/Services/OAuthSecretsRepository.php`.

**Public method signatures stay identical** per D-16 — consumers (EmailScan jobs, Receipts pipeline, OAuth controllers) don't change. The public surface from lines 51-206 of the existing file is the locked API:
- `hasProviderClient(string $provider): bool`
- `saveProviderClient(string $provider, string $clientId, string $clientSecret, string $redirectUri): void`
- `loadProviderClient(string $provider): ?array`
- `loadInbox(int $inboxId): ?InboxCredentials`
- `saveInboxRefreshToken(int $inboxId, …): void`
- `rotateRefreshToken(int $inboxId, string $newRefreshToken, ?string $newAccessToken, ?DateTimeImmutable $expiresAt): void`
- `removeInbox(int $inboxId): void`

**Constructor rewire** — replace existing line 49:
```php
public function __construct(private readonly Filesystem $files) {}
```

with:
```php
public function __construct(
    private readonly DatabaseManager $db,
    private readonly CurrentUser $currentUser,
    private readonly Hasher $hasher,         // only if any internal helper needs it
) {}
```

**Every read** filters `where('user_id', $this->currentUser->id())`. Every write sets `user_id => $this->currentUser->id()`.

**Class-level docblock** — replace existing lines 14-38 (the chmod-600 atomic-write narrative). New docblock describes WHAT the class does today (per-user SQLite-backed OAuth-credentials store with encrypted casts on sensitive columns), NOT what it used to be (CLAUDE.md C-10 + `feedback_docs_describe_current_state.md`).

**The `InboxCredentials` DTO + `SecretsWriteFailed` exception stay** — they're part of the Public surface and consumers depend on them.

---

### `Modules/EmailScan/Providers/EmailScanServiceProvider.php` (MODIFIED)

**Analog:** itself (full file at `/Users/wesselverheij/Development/diederik/Modules/EmailScan/Providers/EmailScanServiceProvider.php`).

**Current binding** (line 76):
```php
$this->app->singleton(OAuthSecretsRepository::class);
```

**No change needed to this line** — Laravel resolves the new constructor signature (`DatabaseManager + CurrentUser + Hasher`) automatically because every parameter is a typed contract that the container already knows how to make. The singleton-binding line stays exactly as-is; only the underlying class changes.

**Verify** — the `OAuthSecretsRepository` consumers (`GoogleOAuthProvider`, `MicrosoftOAuthProvider`, `OAuthClientWizardModal`, `EmailScan/Internal/Http/Controllers/OAuthConnectController.php`, etc.) all already inject `OAuthSecretsRepository` via constructor — none of them touch its internals, so D-16 holds.

---

### `tests/Contracts/BoundaryArchTest.php` (APPENDED — new `noAuthFacadeOrHelper` invariant)

**Analog:** lines 926-1028 of the existing file (two complementary tests: `noFacadeCallsFromCoreConsoleCommands` + `noLaravelGlobalHelpersInCoreConsoleCommands`).

**Existing `noFacadeCallsFromCoreConsoleCommands`** (lines 926-965):
```php
it('does not allow Laravel facades inside Modules/Core/Internal/Console/ (noFacadeCallsFromCoreConsoleCommands)', function (): void {
    // Phase 11 invariant: Core's console commands take their
    // dependencies through constructor DI exclusively. …
    $hits = [];
    $consoleDir = base_path('Modules/Core/Internal/Console');
    if (! is_dir($consoleDir)) {
        expect(true)->toBeTrue();
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($consoleDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $path = $file->getPathname();
        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match('/Illuminate\\\\Support\\\\Facades\\\\/', $stripped) === 1) {
            $hits[] = $path;
        }
    }

    expect($hits)->toBe(
        [],
        "Modules/Core/Internal/Console/ commands may not import Illuminate\\Support\\Facades\\*. Offenders:\n  ".implode("\n  ", $hits),
    );
});
```

**Existing `noLaravelGlobalHelpersInCoreConsoleCommands`** (lines 967-1028) — same shape but with the banned-function helper-list pattern:
```php
$bannedFunctions = [
    'base_path', 'app_path', 'config_path', 'database_path', 'public_path',
    'resource_path', 'storage_path', 'app', 'resolve', 'config', 'auth',
    'request', 'now', 'today',
];
$pattern = '/(?<![>:])\\b('.implode('|', array_map('preg_quote', $bannedFunctions)).')\\s*\\(/';
// (recursive-grep loop identical to above)
```

**Apply to Phase 12 `noAuthFacadeOrHelper`** — full draft in research §Pattern 7 lines 900-961. Key changes from the analog:
- Scope is `base_path('Modules')` (entire module tree), not `base_path('Modules/Core/Internal/Console')`.
- Skip files matching `'/tests/'` in the path.
- Skip files in the explicit D-24 allow-list (array of relative paths).
- Banned patterns: `Illuminate\\Support\\Facades\\Auth`, `Auth::user(`, `Auth::id(`, `Auth::loginUsingId(`, `auth(`, `request()->user(`, `request()->session(`, `session(` (last three with `(?<![>:])` lookbehind to distinguish helper from method call).

**Critical:** The comment-stripping (`preg_replace('#/\*.*?\*/|//[^\n]*#s', ...)`) is verbatim from the analog (line 955 / 1018) — keep it identical so PHPDoc `@see Auth::user()` references stay legal.

---

### `Modules/Auth/tests/Feature/CrossUserIsolationTest.php` (feature-test, D-25)

**Analog for dataset shape:** `Modules/Core/tests/Unit/SystemAlertsMigrationTest.php` lines 92-114 (`->with([...])` Pest dataset).

**Pattern**:
```php
})->with([
    'invalid',
    '',
    'CRITICAL',
    'urgent',
]);

it('accepts every documented system_alerts.severity enum value', function (string $severity): void {
    // …
})->with([
    'info',
    'warning',
    // …
]);
```

**Phase 12 application** — full draft in research §Pattern 8 lines 986-998. Apply:
- Build the dataset by introspecting `Route::getRoutes()` at test boot via `$this->app->make(\Illuminate\Routing\Router::class)->getRoutes()`.
- Filter to routes in the `auth` middleware group with a model-scoped URI parameter (`{transaction}`, `{chain}`, `{recurring}`, etc.).
- Pest `->with(...)` accepts a closure that returns an iterable; use that to build the matrix lazily.

**Analog for `actingAs($user)` + creating per-user fixtures:** `Modules/Categorization/tests/Feature/AssignCategoryTest.php` lines 18-80 — uses `$this->user = User::create([...])`, `$this->actingAs($this->user)`, and per-user fixture creation. Mirror this shape for the two-user setup (alice + bob) inside the cross-user test.

---

### `Modules/Auth/tests/Feature/*Test.php` (LoginPage, SignupPage, …) — 7 files

**Analog:** `Modules/Categorization/tests/Feature/AssignCategoryTest.php` (lines 18-80 = full beforeEach + acting-as pattern). Use:
- `beforeEach()` creates a User + acts as them.
- `Livewire::test(LoginPage::class)` or similar to drive the page component.
- Each test asserts both side effects (DB rows, session keys) AND user-visible UI (`assertSeeText`, error messages from copy contract).

**Per-test imports** match the test target — e.g. `LoginPageTest.php` imports `Modules\Auth\Internal\Http\Livewire\LoginPage`.

---

### `Modules/Auth/tests/Unit/*Test.php` (RecoveryCodeGenerator, RecoveryCodeAuthenticator, …)

**Analog:** `Modules/Core/tests/Unit/SystemAlertsMigrationTest.php` for dataset shape + raw assertions.

**Pattern:** Pure-function tests on stateless services (generator, formatter, normalizer) need no fixtures — instantiate the class directly via `new RecoveryCodeGenerator()`, call the method, assert the regex matches.

For tests that need DI'd collaborators (`RecoveryCodeAuthenticator` needs `Hasher + DatabaseManager + Clock`), resolve them via `$this->app->make(Hasher::class)` inside `beforeEach()` — the Pest test base in the codebase already binds these.

---

### `Modules/Auth/Routes/web.php` (route-file)

**Analog:** `Modules/Core/Routes/web.php` (lines 1-33) for middleware groups + the closure-based route handler shape with constructor-DI'd services.

**Categorization-style Route::view() routes** (`Modules/Categorization/Routes/web.php` lines 7-10):
```php
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/uncategorized', 'categorization::triage')->name('uncategorized');
    Route::view('/rules', 'categorization::rules')->name('rules');
});
```

**Phase 12 routes need to span THREE groups:**

1. **Guest routes (no `auth` middleware)** — `/login`, `/signup`, `/reset-password`. The `/signup` route returns 404 when `User::count() > 0` per D-03 — implement via a closure that throws `NotFoundHttpException` early, or guard the route declaration with a runtime check.

2. **`auth` middleware group** — `/logout`, `/change-password`, `/settings/users/new`, `/settings/users/{username}`, `/settings/recovery-codes`. Add the new `ImpersonationBannerMiddleware` + `ForcePasswordChangeMiddleware` to the group.

3. **`auth` + `is_developer` gate** — `/settings/users/new`, `/settings/users/{username}` per D-03. Use a `developer` middleware alias (registered in `AuthServiceProvider::boot()` via the `Router::aliasMiddleware()` method via DI — `Modules/Core/Routes/web.php` line 9 imports `Illuminate\Support\Facades\Route` so the project allows Facades\Route in route files only as a route-DSL surface — see `BoundaryArchTest.php` line 53's facade rule actually DOES forbid it across `Modules`; verify whether route files have a carve-out by reading the existing route files — they all use `Illuminate\Support\Facades\Route`, so route files DO have an implicit carve-out for the routing DSL).

**Note for planner:** Verify the route-file carve-out before writing the rule. The `BoundaryArchTest::"no Laravel facade usage in module code"` rule (line 52) does NOT explicitly except route files, yet every existing route file uses `Illuminate\Support\Facades\Route`. Run `vendor/bin/pest --filter='no Laravel facade'` after a Phase 12 dry-run to confirm route files pass (they always have — this is a known-good carve-out).

---

## Shared Patterns

### Constructor DI shape (applies to every action, every service, every middleware)

**Source:** `Modules/Categorization/Public/Actions/CreateCategorizationRule.php` lines 58-61 (action) + `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` line 49 (service) + `Modules/Core/Internal/Http/Middleware/LoopbackOnly.php` (no-constructor middleware variant).

**Apply to:** All 8 actions in `Modules/Auth/Public/Actions/`, both services in `Modules/Auth/Internal/Recovery/`, both middlewares in `Modules/Auth/Internal/Http/Middleware/`, all 3 CLI commands.

```php
final class XAction
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        // … each dependency is a contract or framework class, never a facade or helper
    ) {}
}
```

**Variants:**
- `final readonly class` is preferred when no behaviour needs late-bound state.
- Livewire components must NOT use constructor DI (project convention enforced by strict-rules) — DI on action methods instead.

---

### `BelongsToUser` trait on every per-user model

**Source:** `Modules/Categorization/Models/CategorizationRule.php` line 41 (`use BelongsToUser;`).

**Apply to:**
- `Modules/Auth/Models/UserRecoveryCode.php`
- `Modules/EmailScan/Models/OAuthSecret.php`

The trait pulls in the `UserScope` global scope (silently no-ops in unauthenticated contexts per `Modules/Core/Public/Scopes/UserScope.php`). Per-user filtering at the read service layer (explicit `->where('user_id', $userId)`) is still required when the call site is not guard-authenticated (CLI, queue worker without auth context) — see `Modules/Categorization/Models/CategorizationRule.php` docblock lines 14-19 for the explicit warning about unauthenticated-context fallthrough.

---

### System alerts emission (D-13, D-18)

**Source:** `Modules/Core/Public/Services/SystemAlertQuery.php` lines 1-105 (read-side) + `Modules/Core/Models/SystemAlert.php` lines 49-78 (model fillable + casts).

**Apply to:** `RecoveryCodeAuthenticator::emit()`, `ImpersonateUserAction`, `EndImpersonationAction`, `EmitOAuthReauthRequiredAlert` listener.

The codebase does NOT currently have a Public `SystemAlertEmitter` service — writes go through direct `DatabaseManager::table('system_alerts')->insert([...])` calls (see Phase 11 patterns) OR through `SystemAlert::create([...])` Eloquent model writes (the model is fillable, line 49).

**Planner decision needed:** Either (a) extend `Modules/Core/Public/Services/` with a new `SystemAlertEmitter` and route every Phase 12 alert-write through it, or (b) accept that Auth code writes to the table directly via Eloquent (`SystemAlert::create([...])`) since the model is in `Modules/Core/Public/Models/Models` namespace and the Public namespace barrier is honored. Pattern (b) matches the existing codebase shape; pattern (a) is cleaner for testability.

**Severity values** — locked enum on the schema. Reading `SystemAlert::create([...])` will write through the trigger pair. The valid set per `Modules/Core/tests/Unit/SystemAlertsMigrationTest.php` lines 112-114: `info`, `warning`, `critical`. UI-SPEC.md uses `error` in places — verify whether `error` is a synonym for `critical` or a fourth tier (likely the latter — research §UI-SPEC line 161 says "severity = error" and `system_alerts.severity` schema must include it; check schema before implementing).

---

### `noAuthFacadeOrHelper` allow-list (D-24)

**Source:** verbatim list from CONTEXT.md D-24 / research lines 914-928.

**Allow-list of EXACT file paths (relative to repo root):**
```
Modules/Auth/Public/Actions/LoginAction.php
Modules/Auth/Public/Actions/SignupAction.php
Modules/Auth/Public/Actions/LogoutAction.php
Modules/Auth/Public/Actions/ResetPasswordAction.php
Modules/Auth/Public/Actions/RegenerateRecoveryCodesAction.php
Modules/Auth/Public/Actions/ImpersonateUserAction.php
Modules/Auth/Public/Actions/EndImpersonationAction.php
Modules/Auth/Public/Actions/AddUserAction.php
Modules/Auth/Internal/Fortify/FortifyServiceProvider.php
Modules/Auth/Internal/Fortify/Authenticator.php
Modules/Auth/Internal/Fortify/CreateNewUser.php          # only if it exists; SignupAction replaces it
Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php
```

**Critical:** The allow-list is per-file precise (not glob). Adding a future file to the allow-list requires both editing the array AND justifying the addition in a code review.

---

### Error response — always 404, never 403, for cross-user reads (D-25)

**Source:** `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` lines 60-65:
```php
if ($row === null) {
    throw new NotFoundHttpException('System alert not found.');
}
```

**Apply to:** Every Phase 12 action that looks up a user-scoped row (ResetPasswordAction, RegenerateRecoveryCodesAction, AddUserAction lookups, ManageUserPage lookups). Never `throw new AccessDeniedHttpException`.

---

### Class-level docblocks describe CURRENT state, never history (C-10)

**Source:** `Modules/Categorization/Models/CategorizationRule.php` lines 10-37, `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` lines 14-43.

**Anti-pattern (forbidden):** "Previously this was a JSON file; now it's a SQLite table." → write "Stores per-user OAuth client credentials in the oauth_secrets table; values flagged sensitive are AES-256-CBC encrypted via Laravel's `encrypted` cast."

The `OAuthSecretsRepository` rewrite's docblock is the largest at-risk surface — it currently describes the chmod-600 atomic-write sequence (lines 14-38). Replace entirely; don't append "Phase 12 changed this to …".

---

### Migration class-level docblocks (same rule)

**Source:** `Modules/EmailScan/Database/Migrations/2026_05_16_020001_create_inboxes_table.php` lines 11-26 — describes WHAT the table is for, ZERO mention of "added in Phase X" or "supersedes Y".

**Apply to:** All 6 Phase 12 migrations. Especially the `migrate_legacy_email_oauth_json` migration — it MUST NOT reference `.planning/`, Phase 12, or D-NN codes in any comment or docblock (C-09).

---

### View-template chrome — calm Linear/Notion aesthetic

**Source:** `Modules/Core/Resources/views/auth/login-form.blade.php` (full file at the path; 52 lines) + `Modules/Core/Resources/views/auth/login.blade.php` (the outer `min-h-screen flex items-center justify-center bg-white` wrapper).

**Apply to:** All 8 new Blade templates in `Modules/Auth/Resources/views/livewire/`. Mirror:
- `<header class="space-y-1">` with `<h1 class="text-2xl font-semibold text-slate-900 tracking-tight">`
- `<form method="POST" action="..." class="space-y-4">` for forms
- Inputs: `class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"`
- Submit button: `class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"`
- Inline error: `<p class="text-sm text-rose-600">…</p>`

**UI-SPEC.md §Spacing and Typography are the locked design source** — defer to it. The Blade-shape pattern above is the diederik-house-style execution of those rules.

---

## No Direct Analog Found

| File | Role | Why no analog | Planner action |
|------|------|---------------|----------------|
| `Modules/Auth/Internal/Recovery/RecoveryCodeGenerator.php` | service (random string) | First random-string generator in the repo | Use research §Pattern 3 lines 626-651 verbatim |
| `Modules/Auth/Internal/Recovery/RecoveryCodeFormatter.php` | service (txt builder) | First `.txt` file producer in the repo | Trivial — `implode("\n", $codes)` inside a `final class`; no DI needed |

---

## Metadata

**Analog search scope:** `Modules/Core/`, `Modules/Categorization/`, `Modules/EmailScan/`, `Modules/Ledger/`, `Modules/Recurring/`, `tests/Contracts/`.

**Files scanned:** ~80 source files across actions, models, migrations, Livewire components, middleware, CLI commands, service providers, and arch tests.

**Pattern extraction date:** 2026-05-19.

**Most recently modified analog files (preferred over older):**
- `tests/Contracts/BoundaryArchTest.php` (May 19 — latest)
- `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` (recent)
- `Modules/Recurring/Database/Migrations/2026_05_18_010004_*` (most recent ALTER TABLE)
- `Modules/Categorization/Public/Actions/CreateCategorizationRule.php` (mature action shape)

**Patterns intentionally NOT used:**
- The older `email` Fortify pipeline closure in `Modules/Core/Internal/Providers/FortifyServiceProvider.php` lines 37-53 — Phase 12 rewrites the closure for `username` (D-02) but keeps the surrounding `boot(Hasher, RateLimiter)` shape minus the rate limiter (D-12).
- The chmod-600 atomic-write sequence in `OAuthSecretsRepository::writeAtomic()` (existing lines 258-355) — replaced wholesale by SQLite + Eloquent `encrypted` cast (D-17). The atomic-write tests (`Modules/EmailScan/tests/Unit/Services/OAuthSecretsAtomicRotationTest.php`, `OAuthSecretsTempFileModeTest.php`, `OAuthSecretsDirModeTest.php`) become obsolete — the planner must either delete them or rewrite them to assert the SQLite-transactional shape.

---

## PATTERN MAPPING COMPLETE

**Phase:** 12 - Multi-User Activation
**Files classified:** 42
**Analogs found:** 40 / 42

### Coverage
- Files with exact analog: 30
- Files with role-match analog (close but different data flow): 8
- Files with partial analog (shape only): 2
- Files with no analog: 2 (RecoveryCodeGenerator, RecoveryCodeFormatter — first-of-kind; use research §Pattern 3)

### Key Patterns Identified
- **Public actions** are single-purpose `final class` with `readonly` constructor DI on `DatabaseManager + Clock` (and whatever else is needed), invoked via `__invoke(User $caller, ...)`, transaction-wrapping multi-row writes, throwing `InvalidArgumentException` for validation and `NotFoundHttpException` for cross-user misses.
- **Livewire components** are constructor-FREE per strict-rules; collaborators inject on action methods + `render()`. Layout binding uses `$view->extends('layouts.app', ['title' => ...])` with `@phpstan-ignore-next-line method.notFound`, not the research-draft `#[Layout]` attribute.
- **Migrations** are anonymous classes that resolve `DatabaseManager` via `Container::getInstance()->make(...)` and use `$this->schema()->create()` / `->table()` instead of the `Schema::` facade. SQLite enum-check triggers are paired BEFORE INSERT / BEFORE UPDATE statements.
- **Models** are `final class extends Model` with `use BelongsToUser`, explicit `$fillable` list, `casts()` method returning `'created_at' => 'immutable_datetime'`, and a `@property` PHPDoc block documenting every column.
- **Service providers** register Public actions + Public services as `singleton` in `register()`, then in `boot()` call `loadMigrationsFrom + loadRoutesFrom + loadViewsFrom`, register Livewire components via `$livewire->component(...)`, and listen on Public events via `$events->listen(...)`.
- **Arch tests** (the `noAuthFacadeOrHelper` and `crossUserIsolation` invariants) use the bespoke `RecursiveIteratorIterator + preg_replace comment-stripping + preg_match banned-pattern` shape (NOT the `arch()` plugin) because they need to express symbol-level rules with allow-lists.
- **CLI commands** extend `Illuminate\Console\Command`, declare `$signature` + `$description`, take dependencies through `readonly` constructor DI, and use `$this->secret()` for password prompts. Per D-14, Phase 12 commands refuse `--password=` style options entirely.

### File Created
`/Users/wesselverheij/Development/diederik/.planning/phases/12-multi-user-activation/12-PATTERNS.md`

### Ready for Planning
Pattern mapping complete. Planner can now reference analog patterns by file path + line number in each PLAN.md action.
