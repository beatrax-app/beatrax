---
phase: 06-email-receipt-ingestion-infrastructure
plan: 04
subsystem: email
tags: [email, microsoft, oauth, azure, entra, loopback-redirect, livewire, wizard, uuid-validation]

requires:
  - phase: 06-email-receipt-ingestion-infrastructure
    provides: OAuth surface (GoogleOAuthProvider + AccessTokenWithEmail + OAuthStateRepository + typed sentinels) + OAuthConnectController + OAuthCallbackController + OAuthClientWizardModal (Gmail variant) + InboxesPage (Plan 03)
provides:
  - Two composer packages: microsoft/microsoft-graph + thenetworg/oauth2-azure
  - MicrosoftOAuthProvider thin wrapper over TheNetworg\OAuth2\Client\Provider\Azure with tenant=common + defaultEndPointVersion=2.0 + scope=Mail.Read offline_access User.Read + prompt=consent + IdentityProviderException mapping (invalid_grant → InvalidGrantException, else → OAuthExchangeFailed)
  - OAuthConnectController + OAuthCallbackController $provider dispatch via match arm — selects GoogleOAuthProvider or MicrosoftOAuthProvider; default arm throws NotFoundHttpException
  - OAuthClientWizardModal Microsoft variant: UUID v4 client_id validation + non-empty client_secret validation; no publishedConfirmed gate
  - Blade view: six Azure-specific numbered steps when $provider === 'microsoft' (copy locked verbatim from UI-SPEC § Microsoft variant numbered steps)
  - InboxesPage::openWizard no longer carries the Microsoft "coming soon" toast — Microsoft now flows through the same dispatch path as Gmail
  - OAuthCallbackMicrosoftTest (3 tests) — happy path + state mismatch + canceled-at-consent
  - OAuthClientWizardModalMicrosoftTest (7 tests) — open + Blade render + 3 validation rejections + happy-path + defensive publishedConfirmed-doesn't-gate-Microsoft
affects: [06-05 BackfillInboxJob (Microsoft path consumes the same open_backfill_modal session flash + needs_reauth recovery flow), 06-07 inline row actions (status badge matrix applies to Microsoft inboxes identically; Reconnect dispatches to /oauth/connect/microsoft via the same controller path)]

tech-stack:
  added:
    - "microsoft/microsoft-graph ^3.1 (v3.1.0) — Microsoft Graph REST client (Mail subset; Kiota-generated SDK v2)"
    - "thenetworg/oauth2-azure ^2.2 (v2.2.5) — Azure Active Directory OAuth 2.0 client provider; supports v2.0 endpoint + 'common' tenant + offline_access scope"
  patterns:
    - "Single Livewire SFC carries both Gmail and Microsoft variants; submit() branches on $provider and Blade renders the appropriate numbered-step block via @if / @elseif. Mirrors the wizard's locked UI-SPEC § Microsoft variant numbered steps copy verbatim."
    - "Controller dispatch via match($provider) — same shape used for /oauth/connect/{provider} and /oauth/callback/{provider}; default arm throws NotFoundHttpException so /oauth/connect/foo and /oauth/callback/foo both 404. Pattern extends naturally to any future provider."
    - "MicrosoftOAuthProvider mirrors GoogleOAuthProvider's public surface (getAuthorizationUrl + exchangeAuthorizationCode + refreshAccessToken + readEmail). Both wrap a per-provider league/oauth2-* abstraction; both map IdentityProviderException to the module's typed sentinels with the same safeMessage() 300-char cap; both are non-final so tests can substitute a stub subclass via $this->app->instance()."
    - "Microsoft email read-back uses TheNetworg's get('https://graph.microsoft.com/v1.0/me', $token) — passes the token by reference (the underlying provider auto-rotates expired tokens mid call). Prefers the `mail` field; falls back to `userPrincipalName` for personal Outlook.com accounts where `mail` is often null."

key-files:
  created:
    - Modules/EmailScan/Internal/OAuth/MicrosoftOAuthProvider.php
    - Modules/EmailScan/tests/Feature/OAuthCallbackMicrosoftTest.php
    - Modules/EmailScan/tests/Feature/OAuthClientWizardModalMicrosoftTest.php
  modified:
    - composer.json
    - composer.lock
    - Modules/EmailScan/Internal/Http/Controllers/OAuthConnectController.php
    - Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php
    - Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php
    - Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php
    - Modules/EmailScan/Resources/views/livewire/oauth-client-wizard-modal.blade.php
    - Modules/EmailScan/Providers/EmailScanServiceProvider.php
    - Modules/EmailScan/tests/Feature/OAuthClientWizardModalTest.php

key-decisions:
  - "MicrosoftOAuthProvider declared non-final — mirrors GoogleOAuthProvider's precedent (Plan 03 deviation #3). The OAuthCallbackMicrosoftTest's anonymous-class mock requires extension; declaring final would produce a silent fatal inside Pest's TerminationHandler with zero output. The singleton binding in EmailScanServiceProvider + the constructor signature enforce the contract, not the final modifier."
  - "Email read-back uses the Graph /me endpoint via TheNetworg's get() helper (not the id_token claims). The id_token claims path (via getResourceOwner() → AzureResourceOwner::getEmail()) reads from `email` which is often missing on personal Outlook.com accounts; the Graph /me call returns both `mail` and `userPrincipalName` so we can prefer `mail` and fall back to `userPrincipalName` for the personal-account case. This is why User.Read is in the requested scope set alongside Mail.Read + offline_access."
  - "Token passed by reference into get() — TheNetworg\\OAuth2\\Client\\Provider\\Azure::get($ref, &$accessToken, ...) mutates the supplied token in place (auto-rotates an expired token mid call). Assigning to a local `$tokenRef` variable before passing keeps the reference semantics off the caller-supplied AccessTokenWithEmail DTO (which is final readonly and could not be mutated anyway, but the local binding makes the contract explicit)."
  - "Scope set is Mail.Read + offline_access + User.Read. Mail.Read is read-only (NOT Mail.ReadWrite per D-113). offline_access requests a refresh_token. User.Read is required to read the Graph /me endpoint for the user's identifying email. The combined scope string is passed both on the authorize URL and on the token-exchange call defensively (Azure's behaviour around required scope-on-exchange is configuration-dependent)."
  - "UUID v4 regex is `/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i`. Third group's first nibble in [1-5] is the RFC 4122 version field (4 == v4 by spec, but accepting 1-5 is more permissive and reflects how Azure-issued application IDs occasionally use other versions). Fourth group's first nibble in [89ab] is the RFC 4122 variant field. The pattern matches the canonical 8-4-4-4-12 hex shape Azure documents at learn.microsoft.com."
  - "InboxesPage::openWizard no longer carries the Microsoft 'coming soon' toast branch. The pre-existing test 'Microsoft variant surfaces a coming soon inline error' in OAuthClientWizardModalTest was removed because it would now fail (the test passed an invalid UUID `11111111-2222-3333-4444-555555555555` whose fourth group `4444` violates the v4 variant nibble [89ab] — so the new validation would surface the UUID error, not the obsolete 'coming soon' error). The new OAuthClientWizardModalMicrosoftTest covers all the Microsoft scenarios the old test was a placeholder for."
  - "Controller match($provider) default arm throws NotFoundHttpException so /oauth/connect/yahoo and /oauth/callback/yahoo both return 404. Pre-existing test 'OAuth callback for unknown provider returns 404' (in OAuthCallbackGmailTest) covers the callback path; the connect path's NotFoundHttpException is exercised indirectly through CrossUserInboxIsolationTest's 'unknown provider 404' test which exists from Plan 03."

patterns-established:
  - "Multi-provider OAuth dispatch via match($provider) in invokable controllers — same shape any future provider class (Yahoo, AppleID-mail, etc.) plugs into. The OAuth library wrapper class is the per-provider unit of expansion; the controller layer touches it in exactly one place per route."
  - "Per-provider wizard variant via single Livewire SFC + Blade @if / @elseif branches keyed on $provider. The provider-specific validation lives in submit()'s top-level branch; the shared post-validation persistence + redirect flow lives below the branch. Avoids the duplication of two parallel components for two variants."
  - "Microsoft Graph email read-back via get('/v1.0/me') with `mail` ?: `userPrincipalName` fallback. This is the canonical posture for any future Microsoft Graph read that needs the user's address — personal Outlook.com accounts often null out `mail`."

requirements-completed: [EML-02, EML-03]

duration: ~30min
completed: 2026-05-17
---

# Phase 6 Plan 04: Wave 3 Microsoft 365 OAuth Parity Summary

**MicrosoftOAuthProvider + controller match($provider) dispatch + OAuthClientWizardModal Microsoft variant (six Azure-specific numbered steps + UUID v4 validation) + two new test files covering callback + wizard — Wave 3 ships the end-to-end demoable Microsoft 365 connect slice mirroring the Gmail slice from Plan 03.**

## Performance

- **Duration:** ~30 min (worktree run; includes one-time composer install + .env / public/build / sqlite touch + composer require of the two new packages)
- **Tasks:** 2
- **Files created:** 3 (1 OAuth Internal class + 2 feature tests)
- **Files modified:** 9 (composer.json + composer.lock + 2 controllers + 2 Livewire SFCs + 1 Blade view + EmailScanServiceProvider + 1 test deletion within existing file)

## Accomplishments

- Composer install of two packages succeeds with **zero ext-imap regressions**: `grep -rn "ext-imap" vendor/ --include="*.json" | grep -v /test/` returns no hits; `find vendor -type d -name "webklex"` returns empty; the composer.lock `ext-imap` count is 0; the Phase 1 NoExtImapTest + composer.json conflict block both still hold.
- Total transitive dep additions: 18 packages (tbachert/spi, stduritemplate/stduritemplate, php-http/promise, symfony/polyfill-php82, open-telemetry/sem-conv, open-telemetry/context, open-telemetry/api, nyholm/psr7-server, open-telemetry/sdk, doctrine/annotations, microsoft/kiota-* x6, microsoft/microsoft-graph-core, microsoft/microsoft-graph, thenetworg/oauth2-azure). One transitive deprecation note: doctrine/annotations is abandoned but still functional — pulled in transitively by the Kiota stack, not by our direct require.
- The OAuth callback round-trip is exercisable end-to-end through the test container without any network call: OAuthCallbackMicrosoftTest mocks MicrosoftOAuthProvider via `$this->app->instance(...)`, issues a real state via the live OAuthStateRepository singleton, drives `GET /oauth/callback/microsoft?state=...&code=...`, and asserts the inboxes row + inbox_scan_state row + chmod-600 JSON entry + redirect flash all land correctly.
- Loopback redirect URI computation is **provably server-side**: OAuthCallbackMicrosoftTest asserts the mock's captured `$redirectUri` matches `http://127.0.0.1:{parse_url(app.url, PHP_URL_PORT) ?: 8000}/oauth/callback/microsoft` — independent of any query-string value, mitigating T-06-04-04 (redirect-URI mismatch).
- All three CI gates green: 73 EmailScan tests pass (244 assertions, +9 net new vs Plan 03's 64), 34 cross-cutting Contracts tests pass (122 assertions), PHPStan level 10 strict reports `[OK] No errors` over the full codebase (235 files), Laravel Pint reports `passed` on the EmailScan module + project-wide.
- Full project Pest run: **811 passed**, 5 skipped, **1 failed** — the failure is the documented `<known_failure>` (`Modules/Ledger/tests/Unit/TransactionTypeTest.php:74`); no regressions introduced by Plan 04.

## Task Commits

1. **Task 1: composer require + MicrosoftOAuthProvider + OAuthConnectController + OAuthCallbackController $provider dispatch + OAuthCallbackMicrosoftTest + InboxesPage cleanup** — `366e03a` (feat)
2. **Task 2: OAuthClientWizardModal Microsoft variant + Blade six Azure steps + OAuthClientWizardModalMicrosoftTest + stale "coming soon" test removed** — `aeb0f15` (feat)

## Files Created/Modified

### OAuth surface (Task 1)

- `Modules/EmailScan/Internal/OAuth/MicrosoftOAuthProvider.php` — `class MicrosoftOAuthProvider` (non-final; tests subclass). Constructor: `OAuthSecretsRepository $secrets`. Public methods: `getAuthorizationUrl(string $state, string $redirectUri): string`, `exchangeAuthorizationCode(string $code, string $redirectUri): AccessTokenWithEmail`, `refreshAccessToken(string $refreshToken): AccessTokenWithEmail`, `readEmail(string $accessToken): string`. Per-call instantiation of `TheNetworg\OAuth2\Client\Provider\Azure` with `tenant=common`, `defaultEndPointVersion=2.0`, computed `redirectUri`. Scope set: `Mail.Read offline_access User.Read`. Authorize URL adds `prompt=consent` to force refresh-token re-issue. IdentityProviderException → InvalidGrantException for `invalid_grant`, OAuthExchangeFailed otherwise. safeMessage() truncates IdP error messages to 300 chars.

### Controllers + Service Provider (Task 1)

- `Modules/EmailScan/Internal/Http/Controllers/OAuthConnectController.php` — Constructor adds `MicrosoftOAuthProvider $microsoftOAuth`. Body replaces hardcoded `if ($provider !== 'gmail') throw NotFoundHttpException` with `match ($provider) { 'gmail' => $googleOAuth, 'microsoft' => $microsoftOAuth, default => throw NotFoundHttpException }`. The bound `$oauth` is used at the `$oauth->getAuthorizationUrl(...)` call site.
- `Modules/EmailScan/Internal/Http/Controllers/OAuthCallbackController.php` — Same match dispatch shape; the bound `$oauth` is used at the `$oauth->exchangeAuthorizationCode(...)` call site. Everything below the exchange call (state consume, DB transaction, secrets write, redirect flash) is unchanged — it operates on `$provider` verbatim and the inboxes-table trigger pair enforces the gmail|microsoft allow-list.
- `Modules/EmailScan/Providers/EmailScanServiceProvider.php` — Registers `MicrosoftOAuthProvider` as a singleton alongside `GoogleOAuthProvider`. Docblock updated to reflect that the wizard SFC now carries both variants.
- `Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php` — `openWizard()` no longer carries the Microsoft "coming soon" toast branch. Microsoft now flows through the same logic as Gmail: if the provider client is configured, redirect to `/oauth/connect/microsoft`; if not, dispatch `modal-show` to open the wizard.
- `Modules/EmailScan/Resources/views/livewire/inboxes-page.blade.php` — Comment string updated to remove the "Gmail variant — Microsoft variant lands in the next plan" reference.

### Tests (Task 1)

- `Modules/EmailScan/tests/Feature/OAuthCallbackMicrosoftTest.php` — 3 tests covering happy path (inserts inbox + scan_state row pair, persists refresh_token to chmod-600 JSON, redirects to /inboxes with open_backfill_modal flash carrying the new inbox id, asserts mock-captured redirect URI was computed server-side from app.url), state mismatch (InvalidStateException), canceled-at-consent (oauth_canceled flash + zero rows).

### Wizard modal (Task 2)

- `Modules/EmailScan/Internal/Http/Livewire/OAuthClientWizardModal.php` — Added `MICROSOFT_CLIENT_ID_PATTERN` constant. `submit()` branches: when `$provider === 'microsoft'`, validates UUID v4 client_id and non-empty client_secret; the Google branch still requires publishedConfirmed. Persistence + dispatch + redirect logic shared between branches.
- `Modules/EmailScan/Resources/views/livewire/oauth-client-wizard-modal.blade.php` — Replaced the Microsoft `@else` "coming soon" stub with an `@elseif ($provider === 'microsoft')` block rendering the six Azure-specific numbered steps verbatim from UI-SPEC § Microsoft variant numbered steps: (1) Open Azure Portal + Entra deep-link button, (2) Register a new application, (3) Add the redirect URI + copy-to-clipboard button, (4) Grant Mail.Read permission, (5) Create a client secret, (6) Paste your application (client) ID and secret with the same `<input>` shape as the Google variant. The numbered step circles use the same Tailwind classes; only the copy and the deep-link target URL differ. No publishedConfirmed checkbox in the Microsoft block.

### Tests (Task 2)

- `Modules/EmailScan/tests/Feature/OAuthClientWizardModalMicrosoftTest.php` — 7 tests covering: open(microsoft) sets provider, Blade renders the six Azure-specific numbered steps + the Microsoft variant heading, validation rejections (non-UUID + UUID-shaped-but-non-v4 + empty secret), happy-path persistence + modal-hide + redirect to /oauth/connect/microsoft, defensive check that publishedConfirmed=false does NOT block Microsoft submit.
- `Modules/EmailScan/tests/Feature/OAuthClientWizardModalTest.php` — Stale "Microsoft variant surfaces a coming soon inline error without writing the secrets file" test removed (Microsoft no longer blocked at submit).

## Decisions Made

- **Email read-back via Graph /me, not id_token claims.** TheNetworg's `getResourceOwner()` reads from id_token claims via `AzureResourceOwner::getEmail()` which inspects the `email` claim — frequently null on personal Outlook.com accounts. The Graph `/me` endpoint returns both `mail` and `userPrincipalName`; we prefer `mail` and fall back to `userPrincipalName`. This is why User.Read is in the requested scope set.
- **Token passed by reference into get().** TheNetworg's `Azure::get($ref, &$accessToken, ...)` mutates the supplied AccessToken in place to auto-rotate expired tokens mid call. The implementation assigns to a local `$tokenRef` before passing to keep the by-reference contract explicit (AccessTokenWithEmail is final readonly so it could not be mutated even if passed directly — but the local-binding pattern is the safer code-review surface).
- **Scope set is "Mail.Read offline_access User.Read" — passed both on authorize URL and on token-exchange call.** Azure's behaviour around scope-on-exchange is configuration-dependent; the defensive `'scope' => self::SCOPE_STRING` argument on `getAccessToken('authorization_code', ...)` and `getAccessToken('refresh_token', ...)` ensures the requested scope set is preserved across both calls. Per D-113 the scope is strictly read-only — Mail.Read NOT Mail.ReadWrite.
- **`prompt=consent` mirrors Google's `prompt=consent`.** Forces re-issue of the refresh_token on every consent. Without it, Azure returns an access_token but no refresh_token if the user has previously consented for this client — which would silently break the always-on background scanner.
- **InboxesPage's Microsoft "coming soon" branch removed.** The branch (and its test) were placeholders left by Plan 03 explicitly for Plan 04 to remove. The single-line removal is the cleanest path; the openWizard happy path now treats Microsoft identically to Gmail (check `hasProviderClient`, either redirect to `/oauth/connect/microsoft` or dispatch `modal-show`).
- **Pre-existing OAuthClientWizardModalTest stale-assertion removal vs. update.** The pre-existing "Microsoft variant surfaces a coming soon inline error" test in OAuthClientWizardModalTest used an invalid UUID `11111111-2222-3333-4444-555555555555` (fourth group `4444` violates the v4 variant nibble `[89ab]`). After Plan 04, the test would surface the new UUID-validation error, not the obsolete "coming soon" error. Removed the test entirely — the new OAuthClientWizardModalMicrosoftTest covers all the Microsoft scenarios.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] One-time worktree environment bootstrap (composer install + .env copy + public/build copy + sqlite touch)**

- **Found during:** Task 1 (initial composer require attempt + first test run)
- **Issue:** Same bootstrap deficit Plan 02 + Plan 03 documented — the worktree spawned without `vendor/`, `.env`, `public/build/manifest.json`, or `database/database.sqlite`. composer require + every pest invocation fails until the four files / directories are present.
- **Fix:** `cp /Users/wesselverheij/Development/diederik/.env .env`; `cp -r /Users/wesselverheij/Development/diederik/public/build public/build`; `touch database/database.sqlite`; `composer install --no-interaction --no-progress` (populates vendor/illuminate/* etc.). Then `composer require microsoft/microsoft-graph:^3.1 thenetworg/oauth2-azure:^2.2`.
- **Files modified:** none (every fix touches gitignored paths; `.env`, `vendor/`, `public/build/`, `database/database.sqlite` are all in `.gitignore`).
- **Verification:** Composer install succeeds, full pest run produces visible output, PLT-05 audit grep returns zero non-test hits.

**2. [Rule 1 - Bug] Pint single_blank_line_at_eof on OAuthClientWizardModalTest after removing the stale test**

- **Found during:** Task 2 (post-implementation `vendor/bin/pint --test Modules/EmailScan` gate)
- **Issue:** Deleting the "Microsoft variant surfaces a coming soon inline error" test from OAuthClientWizardModalTest left a trailing blank-line situation Pint's `single_blank_line_at_eof` fixer wanted normalised.
- **Fix:** `vendor/bin/pint Modules/EmailScan/tests/Feature/OAuthClientWizardModalTest.php` applied the fixer in one pass.
- **Files modified:** OAuthClientWizardModalTest.php.
- **Verification:** `vendor/bin/pint --test Modules/EmailScan` exits `passed`.

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 bug)
**Impact on plan:** One blocker was pure worktree-environment bootstrapping (no production code touched — all gitignored paths). One bug was a downstream Pint formatting fix from a planned deletion. No scope creep; no architectural changes; no Rule 4 escalations; no Rule 2 missing-critical-functionality additions.

## Issues Encountered

- **TheNetworg's `getResourceOwner()` returns null email for personal accounts.** Initially considered using `getResourceOwner()->getEmail()` (mirrors the Google variant's pattern). But AzureResourceOwner::getEmail() reads the `email` claim from the id_token, which is often null on personal Outlook.com accounts (Microsoft puts the address in `preferred_username` or returns it only via Graph `/me`). Switched to the Graph `/me` path which returns both `mail` and `userPrincipalName` and prefers `mail` with `userPrincipalName` as the fallback. Side-effect: User.Read had to be added to the scope set. This is the correct posture for the project's "personal Outlook.com + work Microsoft 365 both supported" requirement (UI-SPEC § Microsoft variant step 2 explicitly mentions "personal Outlook.com" in the body copy).
- **doctrine/annotations transitive deprecation.** Composer install warned `Package doctrine/annotations is abandoned, you should avoid using it. No replacement was suggested.` The package is pulled in by the Kiota stack (transitive of microsoft/microsoft-graph), not by our direct require. We have no control over the abandonment status and Microsoft has not yet migrated. Filed as a known-state note; no action required for Plan 04 — if a security advisory ever lands on the abandoned package we will need to evaluate whether to pin a fork or swap the Microsoft SDK boundary to a thin Guzzle wrapper.
- **`composer show` with multiple package arguments returns no output.** Running `composer show microsoft/microsoft-graph thenetworg/oauth2-azure` (with both names as positional arguments) returned an empty response. Running them as separate invocations (`composer show microsoft/microsoft-graph; composer show thenetworg/oauth2-azure`) returns the expected metadata for both. This is a `composer show` argv-parsing quirk, not a missing-package signal. Versions are pinned correctly: microsoft/microsoft-graph v3.1.0 and thenetworg/oauth2-azure v2.2.5 (both match RESEARCH.md § Standard Stack expectations).

## Output-spec narrative

The plan's `<output>` block asked six specific questions. Answers:

1. **composer show output for the two new packages.** `composer show microsoft/microsoft-graph` reports `name : microsoft/microsoft-graph`, `versions : * v3.1.0`. `composer show thenetworg/oauth2-azure` reports `name : thenetworg/oauth2-azure`, `versions : * v2.2.5`. Both match the RESEARCH.md § Standard Stack expectations (v3.1.0 published 2026-05-07, v2.2.5 published 2026-02-26). 18 transitive packages added (mostly Kiota stack + OpenTelemetry).
2. **UUID v4 regex committed to OAuthClientWizardModal.** Committed as `MICROSOFT_CLIENT_ID_PATTERN` private constant: `/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i`. Matches Azure's documented 8-4-4-4-12 hex shape from learn.microsoft.com. Note the third group's first nibble `[1-5]` accepts UUID versions 1 through 5 (canonical v4 is 4, but accepting the wider range matches how the broader Microsoft ecosystem occasionally assigns non-v4 IDs). Fourth group's first nibble `[89ab]` is the RFC 4122 variant field.
3. **Email read-back method shape.** Used `$provider->get('https://graph.microsoft.com/v1.0/me', $tokenRef)` from `TheNetworg\OAuth2\Client\Provider\Azure::get($ref, &$accessToken, $headers = [], $doNotWrap = false)`. The token is passed by reference because the underlying provider auto-rotates expired tokens mid call; assigning to a local `$tokenRef` variable before passing keeps the reference contract off the caller-supplied AccessTokenWithEmail DTO. Did NOT use `getResourceOwner()->getEmail()` because AzureResourceOwner reads from id_token `email` claim which is often null on personal Outlook.com accounts.
4. **publishedConfirmed gate is NOT applied to Microsoft.** OAuthClientWizardModalMicrosoftTest's last test (`Microsoft submit succeeds with publishedConfirmed=false`) defensively asserts Submit succeeds when publishedConfirmed=false on the Microsoft variant — if a regression ever moved the Google check above the provider branch (or removed the branch entirely), this test would fail. Verified: the assertion passes after submit, the redirect lands on `/oauth/connect/microsoft`, and the chmod-600 JSON has the provider client persisted.
5. **match($provider) controller 404 behaviour.** Both controllers' match expressions have `default => throw new NotFoundHttpException('Unknown provider.')`. The pre-existing `it 'OAuth callback for unknown provider returns 404'` test in OAuthCallbackGmailTest covers the callback path with `/oauth/callback/yahoo` and asserts the throw. Connect-path 404 is covered by the existing CrossUserInboxIsolationTest's `it 'redirects with oauth_failed when no client is configured'` and `it 'returns 404 for unknown provider'` tests from Plan 03 (those still pass after the match refactor).
6. **Deviations from UI-SPEC § Microsoft variant numbered-step copy.** Zero. Every numbered step's heading and body copy is verbatim from UI-SPEC § OAuth-client wizard modal — Microsoft variant numbered steps lines 339-344. The deep-link button label "Open Azure Portal" matches UI-SPEC § Locked Copywriting line 270. The input field labels "Application (client) ID" and "Client secret value" + the helper text under the secret match step 6's spec verbatim. The validation error strings match UI-SPEC § OAuth-client wizard — validation errors lines 352-353 exactly.

## Next Plan Readiness

- Plan 05 (BackfillInboxJob) inherits both provider paths verbatim: the open_backfill_modal session flash works identically for Microsoft inboxes (the OAuthCallbackController writes it regardless of which provider committed the inbox row). The Microsoft variant's refresh-token rotation is single-use (every refresh rotates) — handled identically by OAuthSecretsRepository::rotateRefreshToken which is provider-agnostic.
- Plan 07 (inline row actions) inherits the same Reconnect path: clicking Reconnect on a Microsoft inbox dispatches to `/oauth/connect/microsoft?inbox_id={id}` which OAuthConnectController already handles via the existing reconnect path. The cross-user 404 invariant test pattern from Plan 03 (`CrossUserInboxIsolationTest`) extends to Microsoft trivially — same query (`InboxQuery::findForUser`), same throw site.
- The Microsoft Graph SDK (microsoft/microsoft-graph) is installed but not yet exercised in production code paths — Plan 05 (BackfillInboxJob) and Plan 06 (IncrementalScanJob) will reach into `Microsoft\Graph\GraphServiceClient` to fetch messages. The OAuthSecretsRepository's loadInbox() already returns the access_token + refresh_token + expires_at via InboxCredentials for both providers; the per-message fetch path will inject GraphServiceClient with an access-token authentication provider sourced from those credentials.

## Self-Check: PASSED

Verified after writing this SUMMARY:

- **Created files exist:** All 3 files listed under `key-files.created` resolve on disk (`MicrosoftOAuthProvider.php`, `OAuthCallbackMicrosoftTest.php`, `OAuthClientWizardModalMicrosoftTest.php`).
- **Commits exist:** `git log --oneline -3` shows `aeb0f15 366e03a 2f87d07` in reverse-chronological order on the current worktree branch.
- **Test suite green:** `vendor/bin/pest Modules/EmailScan` reports 73 passed (244 assertions); `vendor/bin/pest tests/Contracts` reports 34 passed (122 assertions); `vendor/bin/phpstan analyse --memory-limit=1G` exits `[OK] No errors` across 235 files; `vendor/bin/pint --test` (project-wide) reports `passed`; full `vendor/bin/pest` reports 811 passed, 5 skipped, 1 failed (the documented `<known_failure>` `Modules/Ledger/tests/Unit/TransactionTypeTest`).

---

*Phase: 06-email-receipt-ingestion-infrastructure*
*Completed: 2026-05-17*
