---
phase: 06-email-receipt-ingestion-infrastructure
plan: 03
subsystem: email
tags: [email, gmail, oauth, loopback-redirect, livewire, wizard, csrf-state, chmod-600]

requires:
  - phase: 06-email-receipt-ingestion-infrastructure
    provides: OAuthSecretsRepository (atomic chmod-600 JSON) + Public DTOs (InboxHealthDto, InboxHealthLine, KnownSenderDto, InboxCredentials) + five Phase-6 tables with FND-03 + enum-trigger discipline (Plan 02)
provides:
  - Four composer packages: google/apiclient, league/oauth2-client, league/oauth2-google, zbateson/mail-mime-parser
  - GoogleOAuthProvider thin wrapper over league/oauth2-google with always-on access_type=offline + prompt=consent + typed InvalidGrant / OAuthExchangeFailed mapping
  - Three typed exception sentinels (InvalidGrantException, InvalidStateException, OAuthExchangeFailed) that never carry token payload in the message
  - AccessTokenWithEmail readonly DTO
  - OAuthStateRepository — per-flow random state in session with hash_equals comparison + single-use pop semantics
  - OAuthConnectController — issues state, computes loopback redirect URI server-side from app.url, redirects to provider authorize URL
  - OAuthCallbackController — verifies state, exchanges code, inserts inbox + inbox_scan_state row pair atomically, persists refresh_token to chmod-600 JSON, redirects to /inboxes with open_backfill_modal flash
  - InboxQuery (forCurrentUser + findForUser + reviewBadgeCount) + InboxesBadgeCount + KnownSenderQuery Public read services
  - InboxesPage Livewire SFC at GET /inboxes with empty-state hero + connected-inboxes table + Add-inbox card pair
  - OAuthClientWizardModal (Gmail variant) Livewire SFC with format validation + mandatory publishedConfirmed checkbox
  - Three named routes: inboxes.index, oauth.connect, oauth.callback (all auth-gated under web middleware)
  - PROJECT.md + README.md amendments documenting the http://127.0.0.1:PORT/oauth/callback/{provider} loopback redirect URI scheme
affects: [06-04 Microsoft variant of the wizard + Microsoft provider class + Microsoft branch in both controllers, 06-05 BackfillInboxJob (auto-opens BackfillWindowModal off the open_backfill_modal flash), 06-07 inline row actions (Scan-Now, Reconnect, Edit-window) + status badge matrix + needs_reauth recovery flow, 06-08 discovered-senders panel + DiscoveryScanJob]

tech-stack:
  added:
    - "google/apiclient ^2.19 (v2.19.3) — Gmail API client (full SDK; transitive deps include google/auth, firebase/php-jwt, google/apiclient-services)"
    - "league/oauth2-client ^2.9 (v2.9.0) — generic OAuth2 client foundation"
    - "league/oauth2-google ^5.0 (v5.0.0) — Google OAuth2 provider (authorize + token exchange + GoogleUser resource owner with getEmail())"
    - "zbateson/mail-mime-parser ^4.0 (v4.0.1) — RFC 822 + MIME parser for the future header-extraction stage; pulled in early so transitive deps land in this plan rather than the parser plan"
  patterns:
    - "OAuth library wrappers (Google + Microsoft) live as non-final Internal classes so feature tests can substitute stub subclasses via $this->app->instance() — same shape OAuthSecretsRepository's performRename() failure-injection hook uses"
    - "Per-flow CSRF state for OAuth callbacks: hash_equals comparison + single-use session pop"
    - "Loopback IP redirect URI computed server-side from the injected Config repository — never read from query string (T-06-03-03 mitigation)"
    - "Feature tests for OAuth flows mock the GoogleOAuthProvider via container instance() rather than over the network — RefreshDatabase + actingAs + the real OAuthStateRepository singleton round-trips state correctly"
    - "Livewire 4 redirectRoute() instead of $this->redirect(route(...)) — keeps the larastan-strict-rules noGlobalLaravelFunction guard satisfied without an ignore directive"

key-files:
  created:
    - Modules/EmailScan/Internal/OAuth/AccessTokenWithEmail.php
    - Modules/EmailScan/Internal/OAuth/InvalidGrantException.php
    - Modules/EmailScan/Internal/OAuth/InvalidStateException.php
    - Modules/EmailScan/Internal/OAuth/OAuthExchangeFailed.php
    - Modules/EmailScan/Internal/OAuth/GoogleOAuthProvider.php
    - Modules/EmailScan/Internal/OAuth/OAuthStateRepository.php
    - Modules/EmailScan/Internal/Http/Controllers/OAuthConnectController.php
    - Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php
    - Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php
    - Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php
    - Modules/EmailScan/Public/Services/InboxQuery.php
    - Modules/EmailScan/Public/Services/InboxesBadgeCount.php
    - Modules/EmailScan/Public/Services/KnownSenderQuery.php
    - Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php
    - Modules/EmailScan/Resources/views/livewire/oauth-client-wizard-modal.blade.php
    - Modules/EmailScan/tests/Unit/OAuth/StateMismatchTest.php
    - Modules/EmailScan/tests/Feature/OAuthCallbackGmailTest.php
    - Modules/EmailScan/tests/Feature/CrossUserInboxIsolationTest.php
    - Modules/EmailScan/tests/Feature/InboxesEmptyStateTest.php
    - Modules/EmailScan/tests/Feature/OAuthClientWizardModalTest.php
  modified:
    - composer.json
    - composer.lock
    - Modules/EmailScan/Providers/EmailScanServiceProvider.php
    - Modules/EmailScan/Routes/web.php
    - .planning/PROJECT.md
    - README.md

key-decisions:
  - "GoogleOAuthProvider is non-final (not `final class`). The feature tests substitute a stub subclass via $this->app->instance() that overrides exchangeAuthorizationCode + getAuthorizationUrl so the network is never reached. Trying to extend a final class produces a fatal that Pest silently swallows — the non-final declaration is load-bearing for the OAuthCallbackGmailTest to even produce visible output."
  - "Loopback redirect URI is computed in OAuthConnectController AND OAuthCallbackController via parse_url((string) $config->get('app.url'), PHP_URL_PORT) ?? 8000. Identical code in both controllers — the URI must match exactly across the authorize + exchange round-trip or Google's IdP rejects with redirect_uri_mismatch. The OAuthCallbackGmailTest asserts the mock receives the value parse_url(app.url)+8000 so the server-side computation is provably what Google sees."
  - "Wizard test isolation uses real Livewire::actingAs($user)->test(OAuthClientWizardModal::class) rather than HTTP — the modal never has its own page route, so the only legitimate test path is through the Livewire test harness. Validation rejections are verified by ->assertSet('errorMessage', ...) matching the locked UI-SPEC copy verbatim."
  - "The 'open_backfill_modal' session flash carries the new inbox_id as an int. Plan 03 stops here — the actual backfill modal SFC + job dispatch lands in Plan 05. The InboxesPage SFC reads the flash on mount() and exposes the value as $openBackfillForInboxId so Plan 05's modal SFC can dispatch modal-show from a single Blade @if branch."
  - "Routes for /inboxes + /oauth/connect/{provider} + /oauth/callback/{provider} all sit under the 'web' + 'auth' middleware group. The callback being auth-gated is deliberate — the user must be the same authenticated session that initiated the connect step (else the cross-user-leak risk reopens)."

patterns-established:
  - "OAuth library wrapper pattern: non-final Internal class, constructor-injected OAuthSecretsRepository for credentials, always-on `accessType=offline` + `prompt=consent`, IdentityProviderException mapped to either InvalidGrantException (refresh required) or OAuthExchangeFailed (other failures) — both message-sanitised so the JSON payload never leaks. The Microsoft variant in Plan 04 mirrors the entire surface unchanged."
  - "Server-side loopback redirect URI computation: parse_url((string) $config->get('app.url'), PHP_URL_PORT) ?? 8000 — never read from the query string (T-06-03-03). Wizard + connect + callback controllers all use the identical formula."
  - "Per-flow CSRF state stored in the Laravel session, comparison via hash_equals, single-use semantics (session->pull). Same shape Microsoft + any future provider needs."
  - "Livewire wizard modal pattern: #[On('event:open')] setter for the provider, public form-state properties for the paste-back fields, submit() validates + writes via the Public repository singleton + dispatches modal-hide + redirects to /oauth/connect/{provider}. Validation surfaces inline 12px text-rose-600 errorMessage strings matching UI-SPEC § Copywriting Contract verbatim."

requirements-completed: [EML-01, EML-03, PLT-03]

duration: ~45min
completed: 2026-05-16
---

# Phase 6 Plan 03: Wave 2 Gmail OAuth Vertical Slice Summary

**Four composer packages + GoogleOAuthProvider + OAuthStateRepository + OAuthConnectController + OAuthCallbackController + Three Public read services + /inboxes Livewire SFC empty-state hero + OAuthClientWizardModal (Gmail variant) + PROJECT.md + README.md amendments — Wave 2 ships the end-to-end demoable Gmail connect slice.**

## Performance

- **Duration:** ~45 min (worktree run; includes one-time composer install + .env / public/build / sqlite touch + composer require of the four new packages)
- **Tasks:** 3
- **Files created:** 20 (6 OAuth Internal + 2 controllers + 2 Livewire SFCs + 3 Public services + 2 Blade views + 1 unit test + 4 feature tests)
- **Files modified:** 6 (composer.json + composer.lock + EmailScanServiceProvider + Routes/web.php + .planning/PROJECT.md + README.md)

## Accomplishments

- Composer install of four packages succeeds with **zero ext-imap regressions**: `grep -rn "ext-imap" vendor/ --include="*.json" | grep -v /test/` returns no hits; `find vendor -type d -name "webklex"` returns empty; the Phase 1 NoExtImapTest + composer.json conflict block both still hold.
- Total transitive dep additions: 13 packages (firebase/php-jwt, google/apiclient, google/apiclient-services, google/auth, league/oauth2-client, league/oauth2-google, php-di/invoker, php-di/php-di, psr/cache, symfony/polyfill-iconv, zbateson/mail-mime-parser, zbateson/mb-wrapper, zbateson/stream-decorators). vendor/ now lists 176 packages.
- The OAuth callback round-trip is exercisable end-to-end through the test container without any network call: the OAuthCallbackGmailTest mocks GoogleOAuthProvider via `$this->app->instance(...)`, issues a real state via the live OAuthStateRepository singleton, drives `GET /oauth/callback/gmail?state=...&code=...`, and asserts the inboxes row + inbox_scan_state row + chmod-600 JSON entry + redirect flash all land correctly.
- Loopback redirect URI computation is **provably server-side**: the OAuthCallbackGmailTest asserts the mock's captured `$redirectUri` matches `http://127.0.0.1:{parse_url(app.url, PHP_URL_PORT) ?: 8000}/oauth/callback/gmail` — independent of any query-string value, mitigating T-06-03-03 (redirect-URI smuggling).
- Cross-user 404 invariant verified: the CrossUserInboxIsolationTest seeds user A's inbox row, authenticates as user B, and confirms `GET /oauth/connect/gmail?inbox_id={user A's id}` throws NotFoundHttpException.
- All three CI gates green: 64 EmailScan tests pass (203 assertions), 34 cross-cutting Contracts tests pass (122 assertions), PHPStan level 10 strict reports `[OK] No errors` over the full codebase (224 files), Laravel Pint reports `passed` on the EmailScan module.
- Full project Pest run: **802 passed**, 5 skipped, **1 failed** — the failure is the documented `<known_failure>` (`Modules/Ledger/tests/Unit/TransactionTypeTest.php:74`) confirmed by the executor prompt; no regressions introduced by Plan 03.

## Task Commits

1. **Task 1: composer require + GoogleOAuthProvider + OAuthStateRepository + typed exceptions + StateMismatchTest + PROJECT.md/README amendments** — `274b0cb` (feat)
2. **Task 2: OAuth controllers + routes + Public read services + InboxesPage skeleton + OAuthCallback/CrossUserInboxIsolation feature tests** — `a15b3fb` (feat)
3. **Task 3: OAuthClientWizardModal (Gmail variant) + Blade views + Livewire registration + InboxesEmptyState/OAuthClientWizardModal tests** — `b27eccb` (feat)

## Files Created/Modified

### OAuth surface (Task 1)

- `Modules/EmailScan/Internal/OAuth/AccessTokenWithEmail.php` — `final readonly` constructor-property DTO (accessToken, refreshToken, expiresAt, scope, email)
- `Modules/EmailScan/Internal/OAuth/InvalidGrantException.php` — `final class … extends RuntimeException` sentinel for the needs_reauth transition
- `Modules/EmailScan/Internal/OAuth/InvalidStateException.php` — `final class … extends RuntimeException` for OAuth state mismatch (HTTP 400 at the route layer)
- `Modules/EmailScan/Internal/OAuth/OAuthExchangeFailed.php` — `final class … extends RuntimeException` for non-invalid_grant IdP failures; message never carries the token payload
- `Modules/EmailScan/Internal/OAuth/GoogleOAuthProvider.php` — `class GoogleOAuthProvider` (NOT final; tests subclass) wrapping `League\OAuth2\Client\Provider\Google` with always-on `accessType=offline` + `prompt=consent`; safeMessage() truncates IdP error messages to 300 chars to prevent flash-payload contamination
- `Modules/EmailScan/Internal/OAuth/OAuthStateRepository.php` — `final class` injecting `Illuminate\Contracts\Session\Session` + `Modules\Core\Public\Contracts\Clock`; `issueState`/`consumeState`/`issueClientWizardSuccess` per the plan's interface contract

### Tests + amendments (Task 1)

- `Modules/EmailScan/tests/Unit/OAuth/StateMismatchTest.php` — 10 tests covering provider validation, single-use semantics, hash_equals correctness, and the wizard-success flag
- `.planning/PROJECT.md` — Append a single paragraph immediately below the "Email integration: Provider APIs only" constraint documenting the loopback redirect URI scheme
- `README.md` — Append an "OAuth redirect URI (email ingestion)" subsection under Setup with the loopback URI explanation + a four-row composer package table

### Controllers + routes + Public services (Task 2)

- `Modules/EmailScan/Internal/Http/Controllers/OAuthConnectController.php` — invokable controller; validates `$provider === 'gmail'` (Microsoft branch lands in Plan 04), redirects to `/inboxes` with `oauth_failed` flash when no client is configured, resolves the reconnect path via `InboxQuery::findForUser` (cross-user 404 invariant), computes the loopback redirect URI via the injected Config repository, issues state, redirects to the Google consent URL
- `Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php` — invokable controller; handles the `error` query parameter (user canceled at consent → `oauth_canceled` flash), verifies state (throws InvalidStateException on mismatch), exchanges the code (maps InvalidGrant + OAuthExchangeFailed to `oauth_failed` flash), inserts both the inboxes + inbox_scan_state rows in a single transaction with `PRAGMA busy_timeout = 5000`, persists the refresh_token to the chmod-600 JSON, redirects to `/inboxes?open_backfill_modal={inboxId}` (flash carrier; modal SFC lands in Plan 05)
- `Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php` — `/inboxes` page SFC; `mount()` reads the `open_backfill_modal` session flash, `openWizard($provider, $secrets)` either redirects to `/oauth/connect/{provider}` (when the client is configured) or dispatches `modal-show` for the wizard, `render()` injects InboxQuery + ViewFactory + CurrentUser
- `Modules/EmailScan/Public/Services/InboxQuery.php` — `final class`; `forCurrentUser` returns `list<InboxHealthDto>` via LEFT JOIN on inbox_scan_state(folder='INBOX'); `findForUser` enforces the cross-user 404 invariant by `where('user_id', $user->id)`; `reviewBadgeCount` sums `discovered_senders` candidates + `inbox_scan_state` needs_reauth
- `Modules/EmailScan/Public/Services/InboxesBadgeCount.php` — `final class`; identical badge sum logic; Plan 07 wires this into the top-nav View Factory composer
- `Modules/EmailScan/Public/Services/KnownSenderQuery.php` — `final class`; returns `list<KnownSenderDto>` filtered to `user_id = $user->id OR user_id IS NULL` (system seeds first, label ASC)
- `Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php` — Blade view with the empty-state hero, the connected-inboxes table (status badge stub; row actions land in Plan 07), and the "Add another inbox" card pair
- `Modules/EmailScan/Routes/web.php` — three routes added inside the existing auth+web middleware group
- `Modules/EmailScan/Providers/EmailScanServiceProvider.php` — registers GoogleOAuthProvider + OAuthStateRepository + InboxQuery + InboxesBadgeCount + KnownSenderQuery as singletons; registers the InboxesPage Livewire component

### Wizard modal + tests (Task 3)

- `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php` — `final class` extends Livewire\Component; #[On('oauth-client-wizard:open')] setter; `submit(OAuthSecretsRepository, ConfigRepository)` validates the Google client_id format (.apps.googleusercontent.com suffix), client_secret prefix (GOCSPX-), and the mandatory publishedConfirmed checkbox; on all-valid persists via `saveProviderClient` and `redirectRoute('oauth.connect', ['provider' => 'gmail'])`
- `Modules/EmailScan/Resources/views/livewire/oauth-client-wizard-modal.blade.php` — First production non-flyout `<flux:modal>`; six numbered steps per UI-SPEC § Google variant; copy-to-clipboard button rendering the loopback URI; Microsoft variant stubbed with "coming soon" copy
- `Modules/EmailScan/Providers/EmailScanServiceProvider.php` (re-modified) — registers the wizard SFC alongside InboxesPage
- `Modules/EmailScan/tests/Feature/InboxesEmptyStateTest.php` — asserts the empty-state hero copy + Connect-button wiring
- `Modules/EmailScan/tests/Feature/OAuthClientWizardModalTest.php` — six tests covering open(), three validation paths, the happy-path persistence + redirect, and the Microsoft "coming soon" stub
- `Modules/EmailScan/tests/Feature/OAuthCallbackGmailTest.php` — four tests covering happy-path + state mismatch + canceled-at-consent + unknown-provider 404; mocks GoogleOAuthProvider via `$this->app->instance(...)` and asserts the loopback redirect URI was computed server-side
- `Modules/EmailScan/tests/Feature/CrossUserInboxIsolationTest.php` — three tests covering the cross-user reconnect 404 + the no-client-configured redirect + the unknown-provider 404

## Decisions Made

- **GoogleOAuthProvider declared non-final.** The plan's text said "final class" but the OAuthCallbackGmailTest needs to extend it with a stub subclass for the test harness — the network call cannot be reached in CI. Plan 02's OAuthSecretsRepository established the same precedent (non-final, with a protected `performRename()` hook for failure injection). Declaring it `final` produces a fatal that Pest silently swallows (visible as exit code 2 with zero output). The non-final declaration plus the singleton binding is the contract enforcement, not the `final` modifier.
- **Loopback redirect URI computed in TWO places.** Both OAuthConnectController and OAuthCallbackController carry an identical `computeLoopbackRedirectUri($provider)` helper that reads `parse_url((string) $config->get('app.url'), PHP_URL_PORT) ?: 8000`. The plan's text uses identical text on both — and the IdP requires the URI to match across authorize + token exchange exactly, so the duplication is load-bearing rather than DRY-violating. Both methods share the same formula; if the formula changes, both controllers change together.
- **`OAuthCallbackController` writes secrets AFTER the DB commit.** Plan text orders the steps as "transaction → save secrets → redirect". The implementation follows that order verbatim. A failure to write secrets after a successful DB commit would leave an orphan inboxes row pointing at a credential that was never persisted — the inverse (DB tx rolled back, secrets written) would orphan a credential pointing at a non-existent inbox id. Today the code performs the DB transaction first then the secrets write; if the secrets write fails the inbox row remains and the reconnect flow can re-rotate the token. This is the safer of the two orphan flavors.
- **`InboxQuery::findForUser` returns null, not throws.** The plan's `<interfaces>` spec said "returns null; HTTP layer throws NotFoundHttpException" — implementation follows that contract verbatim. The OAuthConnectController checks `$inbox === null` and throws NotFoundHttpException at the call site. Keeps the Public service Eloquent-free and the throw scope tight to the HTTP boundary.
- **Used `redirectRoute()` not `$this->redirect(route(...))`.** Livewire's `redirectRoute` method takes the route name + parameters as arguments; using it avoids the `route()` global helper which the larastan-strict-rules `noGlobalLaravelFunction` rule blocks. The plan's text mentioned `$this->redirect(route(...))` — the implementation substitutes the equivalent strict-rules-clean call.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] One-time worktree environment bootstrap (composer install + .env copy + public/build copy + sqlite touch)**

- **Found during:** Task 1 (initial composer require attempt + first test run)
- **Issue:** Same bootstrap deficit Plan 02 documented — the worktree spawned without `vendor/`, `.env`, `public/build/manifest.json`, or `database/database.sqlite`. composer require + every pest invocation fails until the four files / directories are present.
- **Fix:** `cp /Users/wesselverheij/Development/diederik/.env .env`; `cp -r /Users/wesselverheij/Development/diederik/public/build public/build`; `touch database/database.sqlite`; `composer install --no-interaction --no-progress` (populates vendor/illuminate/* etc.). Then `composer require google/apiclient:^2.19 league/oauth2-client:^2.9 league/oauth2-google:^5.0 zbateson/mail-mime-parser:^4.0`.
- **Files modified:** none (every fix touches gitignored paths; `.env`, `vendor/`, `public/build/`, `database/database.sqlite` are all in `.gitignore`).
- **Verification:** Composer install succeeds, full pest run produces visible output, PLT-05 audit grep returns zero non-test hits.

**2. [Rule 1 - Bug] PHPStan strict-rules cleanup in GoogleOAuthProvider**

- **Found during:** Task 1 (post-implementation full `phpstan analyse Modules/EmailScan/Internal/OAuth` gate)
- **Issue:** Initial draft produced 5 strict-rules errors. The `(string) $token->getToken()` casts were flagged as `cast.useless` because the league/oauth2-client AccessToken's `getToken()` already returns string. `$provider->getResourceOwner($tokenObj)->getEmail()` produced a `method.notFound` against `ResourceOwnerInterface::getEmail()` because the interface doesn't carry the method (GoogleUser does). And a stray `@phpstan-ignore` token sequence inside a regular comment was parsed by the analyser and complained as `ignore.parseError`.
- **Fix:** Removed the redundant (string) casts; added an `instanceof GoogleUser` check + a narrow OAuthExchangeFailed throw + a separate catch arm to skip wrapping; rewrote the inline comment to remove the `@phpstan-ignore` token.
- **Files modified:** `Modules/EmailScan/Internal/OAuth/GoogleOAuthProvider.php` (single-file change).
- **Verification:** `vendor/bin/phpstan analyse Modules/EmailScan/Internal/OAuth` exits `[OK] No errors`.

**3. [Rule 1 - Bug] Anonymous class trying to extend a final class — silent fatal in Pest**

- **Found during:** Task 2 (running OAuthCallbackGmailTest for the first time)
- **Issue:** The test harness creates a stub via `new class(...) extends GoogleOAuthProvider {...}`. The initial draft declared `final class GoogleOAuthProvider` per the plan text — which makes the extension a PHP fatal. Pest's TerminationHandler swallowed the fatal and produced **zero output** with exit code 2, which is opaque to debug.
- **Fix:** Removed the `final` modifier and added a docblock note explaining why (mirrors the OAuthSecretsRepository precedent — contract enforced via singleton binding + constructor signature, not the modifier).
- **Files modified:** `Modules/EmailScan/Internal/OAuth/GoogleOAuthProvider.php` (single-file change — toggled `final class` → `class`).
- **Verification:** Re-running OAuthCallbackGmailTest produces 4 visible passing tests (18 assertions). PHPStan + Pint stay green.

**4. [Rule 1 - Bug] OAuthConnectController used `is_numeric` on `$request->query()` return**

- **Found during:** Task 2 (full phpstan gate after implementing the controller)
- **Issue:** PHPStan flagged `is_numeric($reconnectIdRaw)` as `function.impossibleType` — `$request->query('inbox_id')` is typed as `array|null` (the framework accommodates the array-shaped multi-value query parameter form), and is_numeric on those types always returns false.
- **Fix:** Replaced the type predicate with `is_string($reconnectIdRaw) && $reconnectIdRaw !== '' && ctype_digit($reconnectIdRaw)` — narrower and explicit. The cross-user 404 invariant test still passes because the test passes the inbox id as a single string query parameter.
- **Files modified:** `Modules/EmailScan/Internal/Http/Controllers/OAuthConnectController.php` (single-file change).
- **Verification:** PHPStan green; CrossUserInboxIsolationTest still passes.

**5. [Rule 1 - Bug] InboxesPage Livewire SFC used the `route()` global helper**

- **Found during:** Task 2 (full phpstan gate)
- **Issue:** `$this->redirect(route('oauth.connect', ['provider' => $provider]))` triggered the larastan-strict-rules `noGlobalLaravelFunction` rule (the same rule that blocks `auth()`, `config()`, `view()`, etc.).
- **Fix:** Replaced with `$this->redirectRoute('oauth.connect', ['provider' => $provider])` — Livewire's built-in route-aware redirect helper that takes the route name + parameters as method args, no global helper call.
- **Files modified:** `Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php` (single-file change).
- **Verification:** PHPStan green; the openWizard happy path still redirects via the OAuthClientWizardModalTest's `->assertRedirect(route('oauth.connect', ['provider' => 'gmail']))` check.

**6. [Rule 1 - Bug] OAuthCallbackController used a useless `(int)` cast on `insertGetId()`**

- **Found during:** Task 2 (full phpstan gate)
- **Issue:** `(int) $connection->table('inboxes')->insertGetId(...)` was flagged as `cast.useless` because Laravel's `insertGetId` is typed to return `int` directly.
- **Fix:** Removed the cast.
- **Files modified:** `Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php` (single-file change).
- **Verification:** PHPStan green; OAuthCallbackGmailTest still passes.

**7. [Rule 1 - Bug] Pint formatting fixes across 5 files**

- **Found during:** Task 1 + Task 2 (post-implementation `vendor/bin/pint --test Modules/EmailScan` gate)
- **Issue:** Pint reported `fail` on five files across Tasks 1 + 2 — `class_definition` (anonymous-class brace placement), `ordered_imports` (use statement ordering), `fully_qualified_strict_types` (replacing `\League\OAuth2\Client\Token\AccessToken` with an imported `AccessToken`), `new_with_parentheses` (removing redundant `()` on `new DateTimeImmutable()`), `single_line_empty_body` (collapsing empty `{}` blocks).
- **Fix:** `vendor/bin/pint Modules/EmailScan` applied all fixers in one pass.
- **Files modified:** GoogleOAuthProvider.php, StateMismatchTest.php, OAuthCallbackGmailTest.php, InboxQuery.php, KnownSenderQuery.php.
- **Verification:** `vendor/bin/pint --test Modules/EmailScan` exits `passed`.

---

**Total deviations:** 7 auto-fixed (1 blocking, 6 bugs)
**Impact on plan:** One blocker was pure worktree-environment bootstrapping (no production code touched — all gitignored paths). Six bugs were self-introduced and fixed within the same plan cycle. No scope creep; no architectural changes; no Rule 4 escalations.

## Issues Encountered

- **Anonymous class extending a final class silently fatals inside Pest** (Deviation #3 above) — the symptom was `exit code 2, zero stdout, zero stderr`, opaque enough that I initially suspected a bootstrap issue. Removing the `final` modifier resolved it and produced 4 visible passing tests immediately. The lesson: when Pest produces zero output, suspect a fatal during file inclusion (`php -l` won't catch this kind of class-hierarchy fatal).
- **`composer show ... | grep "^name"` returns zero hits** despite all four packages being installed. The `composer show <pkg>` output uses `name     : ...` (multi-space separator before the colon), so a pure `^name` anchor matches; the plan's `grep "^name" | wc -l` returned 0 because the multi-line output starts with `name     : google/apiclient` for each package but only after a blank line — the `composer show <pkg>` invocation shows ONE package's detail, not a list. The plan's gate text was misleading; the substantively correct check is `composer show 2>&1 | grep -cE "^(google|league|zbateson)/" ` which returns the right count (5: apiclient, apiclient-services, auth, oauth2-client, oauth2-google, mail-mime-parser, mb-wrapper, stream-decorators — 8 with transitives). The packages ARE installed; verified independently via `composer info google/apiclient` and the autoload smoke test.
- **The OAuthCallbackController's transaction return value carries through to the post-tx secrets write.** Initially I considered writing the secrets INSIDE the transaction closure, but the file-system write isn't transactional with SQLite — a failed rename mid-tx would leave the row committed but the secrets unwritten. The chosen ordering (DB commit → secrets write) means a failed secrets write leaves the inbox row without credentials; the user can re-run the Connect flow to overwrite the row's secrets entry. The inverse (secrets write → DB) would orphan a credential pointing at an inbox id that doesn't exist if the transaction rolls back. The chosen order is the safer of the two orphan flavors.

## Output-spec narrative

The plan's `<output>` block asked eight specific questions. Answers:

1. **composer install output + transitive dep count.** Four primary packages: google/apiclient (v2.19.3), league/oauth2-client (v2.9.0), league/oauth2-google (v5.0.0), zbateson/mail-mime-parser (v4.0.1). 13 new packages locked into vendor/ in total; vendor/ now lists 176 packages.
2. **`grep -rn "ext-imap" vendor/ --include="*.json"` audit returned zero hits** outside the `/test/` directories — and no false positives. `find vendor -type d -name "webklex"` returns empty. The Phase 1 NoExtImapTest + composer.json conflict block both still hold.
3. **Loopback redirect URI string the wizard surfaces.** Computed server-side as `http://127.0.0.1:` + `parse_url((string) $config->get('app.url'), PHP_URL_PORT) ?: 8000` + `/oauth/callback/gmail`. The port is read from `config('app.url')` via the injected `Illuminate\Contracts\Config\Repository`, NEVER from a query parameter or hardcoded. The fallback `?: 8000` covers the case where `app.url` carries no explicit port (default Herd setup). The OAuthCallbackGmailTest asserts the mock's captured argument matches exactly.
4. **OAuth state single-use semantics verified.** `StateMismatchTest::it state is single-use` issues a state, consumes it once (returns the stored inbox id), consumes it a second time with the same value, asserts null. Plus the cross-cutting hash_equals correctness test that confirms a near-match (one-byte differ at the end) returns null, not a partial accept.
5. **Microsoft wizard variant stub.** Left intentionally stubbed — `submit()` with `$this->provider === 'microsoft'` sets `errorMessage = 'Microsoft setup is available in the next plan.'` and exits early without writing the secrets file. The Blade view's Microsoft branch renders the same "coming soon" copy in place of the numbered-step list. Plan 04 will fill in the real Azure setup steps + the Microsoft regex validation. Verified: `OAuthClientWizardModalTest::it Microsoft variant surfaces a "coming soon" inline error without writing the secrets file` confirms `secrets->hasProviderClient('microsoft')` stays false after a microsoft submit.
6. **`[Edit]` link on inbox secondary line.** Real `<button>` element, not an `<a href="#">`. Click handler is `wire:click="$dispatch('toast', { message: 'Backfill window editing arrives in the next plan' })"` — Plan 05 will replace the toast dispatch with a `modal-show` event scoped to that inbox id. The plan's spec explicitly called the placeholder behaviour out as acceptable for Plan 03.
7. **Blade-layer `session()` / `config()` helpers used.** Yes — used `session()->has('oauth_canceled')` and `session()->has('oauth_failed')` in `inboxes-page.blade.php` for the flash detection. Per the plan's text and the existing project precedent, Blade is allowed to use `session()`/`route()`/`config()` — only module PHP code is constrained to DI-only. No `auth()`/`view()`/`request()` helpers in any new file.
8. **UI-SPEC copy verbatim.** Every copy string in the inboxes page + wizard modal + their associated validation error messages is locked verbatim against `06-UI-SPEC.md` § Copywriting Contract. Verified via the InboxesEmptyStateTest's `->assertSee(...)` matchers and the OAuthClientWizardModalTest's `->assertSet('errorMessage', '...')` matchers — both check the locked strings character-for-character.

## Next Plan Readiness

- Plan 04 (Microsoft variant) inherits the OAuth surface verbatim: it adds a `MicrosoftOAuthProvider` class beside GoogleOAuthProvider, adds a `microsoft` branch to both OAuthConnectController and OAuthCallbackController (single string check change in each), adds the Microsoft regex validation to the wizard's `submit()` method, and adds the Azure-portal numbered steps to the Blade view's `@else` branch. The wizard's `provider` property and `email-scan.oauth-client-wizard-modal` Livewire registration already accommodate the 'microsoft' value.
- Plan 05 (BackfillInboxJob) consumes the `open_backfill_modal` session flash that the OAuthCallbackController already emits. Plan 05's modal SFC reads `$openBackfillForInboxId` from the InboxesPage and dispatches `modal-show` on first render to auto-open the backfill window picker.
- Plan 07 (inline row actions) extends the InboxesPage Livewire SFC with `scanNow($inboxId)` + `reconnect($inboxId)` + `editWindow($inboxId)` action methods; the Reconnect action dispatches to `/oauth/connect/gmail?inbox_id={id}` which the OAuthConnectController already handles via the existing reconnect path. Plan 07 also wires the top-nav badge via the View Factory composer pattern (`InboxesBadgeCount` is the data source — already a singleton).
- The `needs_reauth` state-machine transition (Plan 07) will be the only post-Plan-03 mutator of `inbox_scan_state.status`; the boundary test `noOtherInboxScanStateMutator` is already in place and currently passes trivially since Plan 03 only inserts the initial `idle` row from the OAuthCallbackController.

## Self-Check: PASSED

Verified after writing this SUMMARY:

- **Created files exist:** All 20 files listed under `key-files.created` resolve on disk via `[ -f path ]` checks.
- **Commits exist:** `git log --oneline -3` shows `b27eccb a15b3fb 274b0cb` in reverse-chronological order on the current worktree branch.
- **Test suite green:** `vendor/bin/pest Modules/EmailScan` reports 64 passed (203 assertions); `vendor/bin/pest tests/Contracts` reports 34 passed (122 assertions); `vendor/bin/phpstan analyse --memory-limit=1G` exits `[OK] No errors` across 224 files; `vendor/bin/pint --test Modules/EmailScan` reports `passed`; full `vendor/bin/pest` reports 802 passed, 5 skipped, 1 failed (the documented `<known_failure>` `Modules/Ledger/tests/Unit/TransactionTypeTest`).

---

*Phase: 06-email-receipt-ingestion-infrastructure*
*Completed: 2026-05-16*
