---
phase: 17-ci-cd-pipeline-code-signing
plan: 09a
type: execute
wave: 3
depends_on:
  - 17-06a
files_modified:
  - Modules/Core/Resources/views/livewire/help/data-locations.blade.php
  - Modules/Core/Internal/Http/Livewire/HelpDataLocations.php
  - Modules/Core/Routes/web.php
  - Modules/Core/tests/Feature/HelpDataLocationsTest.php
autonomous: true
requirements:
  - REL-08
requirements_addressed:
  - REL-08
must_haves:
  truths:
    - "/help/data-locations renders a Livewire page showing resolved paths from UserDataPathService + copy-to-clipboard buttons + export-everything CTA gated on Dev Mode"
    - "Section 3 (Deleting your data) renders the verbatim local-only privacy copy"
    - "Cross-user safety: paths shown are scoped to the logged-in user"
  artifacts:
    - path: "Modules/Core/Internal/Http/Livewire/HelpDataLocations.php"
      provides: "Livewire component rendering resolved UserDataPathService paths"
    - path: "Modules/Core/Resources/views/livewire/help/data-locations.blade.php"
      provides: "View with copy-to-clipboard + Dev Mode gated export CTA"
  key_links:
    - from: "/help/data-locations export-everything CTA"
      to: "Modules/DevMode export-everything action (per D-30)"
      via: "DI-injected dev-mode-gated link"
---

<objective>
Ship the /help/data-locations user-facing Livewire page + its Pest test.

Purpose: REL-08 — the user-facing "where is my data?" page that makes the local-only privacy promise tangible. Splitting from 17-09b (.docs/ tree skeleton) and 17-09c (ADRs + architecture topics) keeps this plan focused on code + a single Livewire page; the doc work is its own concern.

Output: A user navigating to /help/data-locations sees their actual data paths + can export everything (when Dev Mode is on) or read instructions when Dev Mode is off.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/17-ci-cd-pipeline-code-signing/17-UI-SPEC.md
@.planning/phases/17-ci-cd-pipeline-code-signing/17-PATTERNS.md
@Modules/Core/Public/Services/UserDataPathService.php
@Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: /help/data-locations Livewire page + Pest test</name>
  <files>Modules/Core/Internal/Http/Livewire/HelpDataLocations.php, Modules/Core/Resources/views/livewire/help/data-locations.blade.php, Modules/Core/Routes/web.php, Modules/Core/tests/Feature/HelpDataLocationsTest.php</files>
  <read_first>
    - Modules/Core/Public/Services/UserDataPathService.php (current — discover the resolver methods that surface the SQLite path, OAuth-secrets path, brand-assets path)
    - Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php (analog — method-parameter DI on render(); Tailwind literal classes; escape policy)
    - Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php (analog — Blade rendering conventions)
    - Modules/DevMode/ (discover if an export-everything action already exists from Phase 16 per D-30 reference; if so, the page links to it; if not, planner picks whether to ship a minimal version here or defer to v1.1 with the "How to manually copy" instructional fallback per UI-SPEC § Section 2 Dev Mode OFF fallback)
    - .planning/phases/17-ci-cd-pipeline-code-signing/17-UI-SPEC.md § "/help/data-locations page" (VERBATIM copy for all sections)
    - Sketch findings skill `Skill("sketch-findings-diederik")` is auto-loaded per CLAUDE.md for Phase 17 surfaces; the help-locations page uses the `.help-locations` CSS classes added in 17-06a; UI-SPEC is authoritative for copy
  </read_first>
  <behavior>
    - Test 1: GET /help/data-locations returns 200 + renders the page title verbatim `Where is my data?`
    - Test 2: Renders the verbatim intro `beatrax stores everything on this device. Nothing is sent to a server, nothing syncs to the cloud, nothing leaves your machine without you exporting it.`
    - Test 3: Section 1 renders the three resolved paths (SQLite, OAuth secrets, brand assets) substituted from the injected UserDataPathService
    - Test 4: Each path row has a copy-to-clipboard button with aria-label `Copy {path-type} path to clipboard`
    - Test 5: When Dev Mode is ON, the Export Everything CTA renders as a primary button; when OFF, the verbatim instructional fallback renders (with the 1. Enable Dev Mode + 2. Manually copy bullet list)
    - Test 6: Section 3 (Deleting your data) renders the verbatim copy including the line `There's no telemetry to opt out of and no remote account to close.`
    - Test 7: Cross-user safety — paths shown are scoped to the logged-in user (BelongsToUser resolution)
  </behavior>
  <action>Step A — Component: create `Modules/Core/Internal/Http/Livewire/HelpDataLocations.php` extending `Livewire\Component`. NO constructor DI. Method-parameter DI on `render()`: inject `UserDataPathService $paths` + `CurrentUser $cu`. `render()` resolves the SQLite path (`$paths->sqliteDatabasePath()` or equivalent — discover during read_first), OAuth secrets path (`$paths->oauthSecretsPath()` or similar), brand-assets/cache path, and a boolean `$devModeOn` from the existing User->is_developer flag. Returns `$views->make('core::livewire.help.data-locations', [...])`.

    Step B — View: create `Modules/Core/Resources/views/livewire/help/data-locations.blade.php`. Layout uses the existing app layout. Sections per UI-SPEC § /help/data-locations page VERBATIM:
    - Page title: `Where is my data?`
    - Intro paragraph (verbatim)
    - Section 1: `Your data lives here` — bold "SQLite database:" / "OAuth secrets:" / "Brand assets + caches:" prefixes each path; paths in `<span class="font-mono">{{ $sqlitePath }}</span>`; copy-to-clipboard button next to each (use Alpine `x-data` + `navigator.clipboard.writeText`)
    - Section 2: `Export everything` — if `$devModeOn`, render the primary button linking to Dev Mode's export-everything action (whichever route discovered); else render the verbatim instructional fallback paragraph
    - Section 3: `Deleting your data` — verbatim copy
    Tailwind classes literal. Use `{{ }}` escaping. Add the `.help-locations` + `.path-row` + `.path-mono` + `.copy-path-btn` CSS classes (added in Plan 17-06a's CSS block) for styling. Aria labels per UI-SPEC § Accessibility Contract.

    Step C — Route: add `Route::get('/help/data-locations', HelpDataLocations::class)->middleware(['web','auth'])->name('core.help.data-locations');` to `Modules/Core/Routes/web.php`. Register the Livewire component in `CoreServiceProvider::boot()` if not auto-discovered.

    Step D — Pest test: write `Modules/Core/tests/Feature/HelpDataLocationsTest.php` covering tests 1-7 using `Livewire::test(HelpDataLocations::class)` + `assertSeeText(...)` against verbatim copy. Use both Dev-Mode-ON and Dev-Mode-OFF user fixtures to cover Test 5 both branches.

    No GSD vocabulary in view copy or PHPDocs.</action>
  <verify>
    <automated>vendor/bin/pest Modules/Core/tests/Feature/HelpDataLocationsTest.php --stop-on-failure && php artisan route:list | grep -q "core.help.data-locations"</automated>
  </verify>
  <done>All 7 behavior tests pass; route registered; view renders with verbatim copy; Dev Mode ON/OFF branches both covered; Larastan + Pint green.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| /help/data-locations rendering | Resolved paths are scoped to logged-in user via UserDataPathService injection; cross-user path leak would be a load-bearing privacy failure |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-09a-01 | Information disclosure | another user's data paths visible on /help/data-locations | mitigate | UserDataPathService is per-user via injection; Test 7 covers cross-user safety |
</threat_model>

<verification>
After Task 1:

1. /help/data-locations page test green (7 behaviors)
2. Route reachable
3. composer test green
</verification>

<success_criteria>
- All 3 must_haves true
- /help/data-locations renders + behaves per UI-SPEC
- Dev Mode ON/OFF both verified
- Cross-user safety verified
</success_criteria>

<output>
Create `.planning/phases/17-ci-cd-pipeline-code-signing/17-09a-SUMMARY.md` capturing: the resolver methods used from UserDataPathService, the Dev Mode export action route name discovered, and any deviations from UI-SPEC.
</output>
