---
status: awaiting_human_verify
trigger: "After completing the signup form on a fresh install, the user is bounced back to the start/welcome page instead of landing on the recovery-codes display."
created: 2026-05-23T00:00:00Z
updated: 2026-05-23T00:00:00Z
---

## Current Focus

hypothesis: EnsureDatabaseReady middleware redirects the Livewire AJAX endpoint (`default-livewire.update`) to `desktop.welcome` BEFORE SignupAction runs, because the route name has no exemption and the user does not yet exist when the signup form is POSTed.
test: `php artisan route:list` to confirm the Livewire update route uses the `web` group and route name is `default-livewire.update`. Then assert SignupPage's `submit` POST gets bounced.
expecting: Livewire endpoint runs on `web` group with no exemption from EnsureDatabaseReady; isFreshInstall()=true at submit time bounces to /welcome.
next_action: Add exempt prefix `livewire` to EnsureDatabaseReady so all Livewire AJAX endpoints pass through; verify via regression test.

## Symptoms

expected: Submitting the signup form on a fresh install navigates the user to `auth.recovery-codes-display` (the recovery-codes page).
actual: User is bounced back to `desktop.welcome` after submitting the signup form.
errors: (none surfaced; redirect appears silent)
reproduction: 1) Fresh install (no users in DB). 2) Open packaged app. 3) Welcome screen renders. 4) Click "Get started" → /signup. 5) Fill username + password (>=12 chars) + confirmation. 6) Submit. 7) User is back at /welcome.
started: Phase 15 packaged-app UAT (UAT-4).

## Eliminated

## Evidence

- timestamp: 2026-05-23T00:00:00Z
  checked: route list and middleware groups via `php artisan route:list --json`
  found: POST /livewire-3a8a6337/update has route name `default-livewire.update` and middleware `["web","Livewire\\Mechanisms\\HandleRequests\\RequireLivewireHeaders"]`. The `web` group contains `EnsureDatabaseReady` (bootstrap/app.php line 32-34).
  implication: Every Livewire AJAX call funnels through `EnsureDatabaseReady`. On a fresh install (User::count() === 0), the gate redirects ANY non-exempt request to `desktop.welcome`. The exempt prefixes are `desktop.setup`, `desktop.welcome`, `signup` — `default-livewire.update` matches none of them.

- timestamp: 2026-05-23T00:00:00Z
  checked: SignupPage::submit() and SignupAction
  found: The submit() method is invoked via the Livewire AJAX POST (`/livewire-XXX/update`), not via a direct request to `/signup`. The middleware runs BEFORE the Livewire mechanism deserializes the component and dispatches `submit()`. So the user is never created in the failing path.
  implication: The transaction is not rolling back — it never starts. The signup form payload arrives at `/livewire-XXX/update`, gets a 302 → /welcome from EnsureDatabaseReady, and SignupAction is never invoked.

- timestamp: 2026-05-23T00:00:00Z
  checked: Existing SignupPageTest (`Livewire::test`) and FirstLaunchBootstrapTest
  found: The Pest test for signup uses `Livewire::test(SignupPage::class)->call('submit')`, which short-circuits the HTTP middleware stack — it invokes the component in-process. So the production middleware regression was never exercised.
  implication: Need a feature test that drives the actual `default-livewire.update` HTTP endpoint with a real Livewire payload, OR a unit-level test that asserts `default-livewire.update` is exempt from the gate. The second is simpler and pins the contract cleanly.

## Resolution

root_cause: EnsureDatabaseReady's exempt-route-name list (`desktop.setup`, `desktop.welcome`, `signup`) does not include the Livewire update endpoint (`default-livewire.update`). On a fresh install, every Livewire AJAX POST is funneled into the gate, which sees `isFreshInstall() === true` and redirects to /welcome — including the AJAX POST that would create the first user. The user therefore never gets created and bounces straight back to the welcome screen.
fix: Added a new `EXEMPT_ROUTE_SUFFIXES` matcher to EnsureDatabaseReady with a single entry, `livewire.update`. The matcher uses `str_ends_with` so it covers both the default Livewire route name (`default-livewire.update`) and any custom-prefixed variant — matching the `*livewire.update` wildcard Livewire uses internally. Result: every Livewire AJAX endpoint passes through the gate while regular page routes remain gated. The component's own host-route name (`signup`) was already exempt via the existing prefix list; the AJAX endpoint needed its own match because the Livewire mechanism re-registers the call under a distinct route name.
verification: All 22 FirstLaunchBootstrapTest assertions pass including 2 new regression tests; full Pest suite (2172 tests) passes; Larastan level 10 strict reports 0 errors; Pint formatting passes.
files_changed:
  - Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php
  - Modules/Desktop/tests/Feature/FirstLaunchBootstrapTest.php
