---
phase: "04-responsive-installable-pwa-seed-008"
plan: "02"
subsystem: "pwa"
tags: ["pwa", "service-worker", "manifest", "offline", "icons", "layout"]
dependency_graph:
  requires:
    - "04-01 (Nyquist RED stubs: PwaLayoutTest, PwaManifestTest, ServiceWorkerRouteTest)"
  provides:
    - "public/site.webmanifest — installable PWA manifest (name beatrax, standalone, 3 icons)"
    - "public/icons/ — icon set (192, 512, 512-maskable, 180 apple-touch-icon)"
    - "public/offline.html — static branded offline fallback page"
    - "resources/views/sw.blade.php — version-tied service worker (beatrax-shell-v{version})"
    - "routes/web.php /sw.js route — unauthenticated, application/javascript, no-cache"
    - "routes/web.php /site.webmanifest and /icons/{icon} routes — public artifacts"
    - "resources/views/layouts/app.blade.php — viewport-fit=cover, PWA head block, SW registration"
    - "PwaLayoutTest GREEN (4/4), PwaManifestTest GREEN (3/3), ServiceWorkerRouteTest GREEN (4/4)"
  affects:
    - "resources/views/layouts/app.blade.php"
    - "routes/web.php"
    - "Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php"
    - "Modules/Core/tests/Feature/PwaLayoutTest.php"
tech_stack:
  added: []
  patterns:
    - "Blade-rendered JS view: sw.blade.php emits JavaScript (not HTML), route sets Content-Type"
    - "Version-tied SW cache name: beatrax-shell-v + config('nativephp.version')"
    - "Privacy-correct SW fetch handler: never caches /livewire /api /desktop; network-first navigation"
    - "PHP GD for apple-touch-icon: opaque #f8fafc background composited at 180x180"
    - "Route::response() with file_get_contents() for manifest JSON (BinaryFileResponse::getContent() returns false)"
key_files:
  created:
    - "public/icons/icon-192.png"
    - "public/icons/icon-512.png"
    - "public/icons/icon-512-maskable.png"
    - "public/icons/apple-touch-icon.png"
    - "public/site.webmanifest"
    - "public/offline.html"
    - "resources/views/sw.blade.php"
  modified:
    - "routes/web.php"
    - "resources/views/layouts/app.blade.php"
    - "Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php"
    - "Modules/Core/tests/Feature/PwaLayoutTest.php"
decisions:
  - "apple-touch-icon generated via PHP GD (not sips) to composite opaque #f8fafc background since sips cannot flatten alpha channels"
  - "/site.webmanifest served via response(file_get_contents()) not response()->file() because BinaryFileResponse::getContent() returns false in test assertions"
  - "PwaLayoutTest uses /help/data-locations not / because dashboard redirects to /imports/new when DB is empty (isFirstRun)"
  - "EnsureDatabaseReady exempt list extended with sw, site.webmanifest, pwa.icon — public PWA artifacts must be accessible before any user exists"
metrics:
  duration: "~45 minutes"
  completed_date: "2026-06-10"
  tasks_completed: 3
  files_changed: 10
---

# Phase 04 Plan 02: PWA Manifest, Icons, Service Worker, Layout Head Summary

**One-liner:** Full minimal PWA layer: web manifest + icon set + version-tied Blade-rendered service worker on an unauthenticated route + static branded offline page + layout head wiring (viewport-fit=cover, manifest link, dual theme-color, apple-touch-icon, SW registration) — all 11 PWA Nyquist tests GREEN.

---

## Tasks Completed

| Task | Description | Commit | Files |
|------|-------------|--------|-------|
| 1 | Generate PWA icon set, manifest, and offline page | `a9aef9d` | 6 files created |
| 2 | Add /sw.js Blade route + SW view + manifest/icon routes + EnsureDatabaseReady fix | `2dd7506` | 3 files modified/created |
| 3 | Wire PWA head block + SW registration into layout | `14c2d92` | 2 files modified |

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing] EnsureDatabaseReady: /sw.js 302-redirected on fresh install**
- **Found during:** Task 2 (ServiceWorkerRoute test was failing with 302)
- **Issue:** The `EnsureDatabaseReady` middleware (appended to the `web` middleware group) redirects ALL web routes to `desktop.welcome` when the users table is empty (fresh install). The `/sw.js` route returned 302 in tests because `RefreshDatabase` creates an empty DB state.
- **Security impact:** Without this fix, browsers on first install would receive a 302 when fetching `/sw.js`, silently preventing `navigator.serviceWorker.register()` from completing. The SW cannot be installed on a fresh app install.
- **Fix:** Added `sw`, `site.webmanifest`, and `pwa.icon` to `EXEMPT_ROUTE_PREFIXES` in `EnsureDatabaseReady::class`. These are intentionally public artifacts — they must be fetchable before any user exists.
- **Files modified:** `Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php`
- **Commit:** `2dd7506`

**2. [Rule 1 - Bug] `/site.webmanifest` route used `response()->file()` which returns empty getContent()**
- **Found during:** Task 2 (PwaManifestTest: manifest body empty)
- **Issue:** `response()->file()` creates a Symfony `BinaryFileResponse` whose `getContent()` method returns `false` (not the file contents) because binary responses are streamed. The test assertion `expect($html)->toContain('beatrax')` got an empty string.
- **Fix:** Changed to `response(file_get_contents(public_path('site.webmanifest')), 200, ['Content-Type' => 'application/manifest+json'])` which returns a standard `Response` with the file content in the body.
- **Files modified:** `routes/web.php`
- **Commit:** `2dd7506`

**3. [Rule 1 - Bug] PwaLayoutTest used `/` which 302-redirects in empty DB state**
- **Found during:** Task 3 (PwaLayoutTest all 4 tests failing with 302)
- **Issue:** The RED stubs were written to test `/` but the dashboard route redirects to `/imports/new` when `isFirstRun` is true (no transactions in DB). In tests using `RefreshDatabase`, there are no transactions, so every `get('/')` returned 302. This was a pre-existing issue in the stubs.
- **Fix:** Changed all 4 test URLs from `/` to `/help/data-locations` — a `Route::view()` that always renders `resources/views/layouts/app.blade.php` with no redirect conditions or user-attribute requirements.
- **Files modified:** `Modules/Core/tests/Feature/PwaLayoutTest.php`
- **Commit:** `14c2d92`

**4. [Rule 2 - Missing] apple-touch-icon generated with PHP GD (not sips) for opaque background**
- **Found during:** Task 1 (sips cannot composite a background color onto PNG)
- **Issue:** The plan called for `sips` to generate the apple-touch-icon with an opaque `#f8fafc` background (iOS ignores transparency). However, `sips` can resize but cannot flatten alpha channels onto a solid color background.
- **Fix:** Used PHP GD (`imagecreatefrompng`, `imagecolorallocate`, `imagefill`, `imagecopyresampled`, `imagepng`) to: (1) allocate a 180x180 image, (2) fill with #f8fafc (RGB 248, 250, 252), (3) composite the source icon over it, (4) save as RGB PNG (no alpha). The result is `8-bit/color RGB` (opaque — correct).
- **Files modified:** `public/icons/apple-touch-icon.png`
- **Commit:** `a9aef9d`

---

## Deferred Human Verification

Per the project checkpoint policy (approved-deferred), the following checkpoint items are recorded here for verification at phase-end UAT:

**Checkpoint: Verify PWA install, SW activation, offline page, and no-financial-cache**

Items to verify at phase-end UAT:
1. Build assets and serve the app (`npm run build`; ensure the dev DB is migrated per project memory)
2. Chrome DevTools → Application → Manifest: confirm name "beatrax", standalone display, and icons load (192, 512, 512 maskable)
3. Application → Service Workers: confirm the SW registered and activated; cache name shows `beatrax-shell-v<version>` (should be `beatrax-shell-v0.0.0-dev` in local dev)
4. DevTools → Network → set Offline → reload a page: the branded "You're offline" page must render (not the browser's dino page)
5. Confirm the install affordance appears (Chrome address-bar install icon, or three-dot menu → Install beatrax)
6. Confirm NO authenticated/financial page HTML is stored in the Cache Storage (only static CSS/JS, icons, and /offline.html)

---

## Quality Gate Results

| Gate | Status | Notes |
|------|--------|-------|
| Pint | PASS | All modified PHP files pass `--test` |
| Larastan level 10 | PASS | `EnsureDatabaseReady.php` — no errors |
| routes/web.php Larastan | SKIP | Excluded from phpstan.neon paths (only `Modules`, `app`, `bootstrap/app.php`) |
| PwaLayoutTest | PASS | 4/4 GREEN (was: 4/4 RED stubs) |
| PwaManifestTest | PASS | 3/3 GREEN (was: 3/3 RED stubs) |
| ServiceWorkerRouteTest | PASS | 4/4 GREEN (was: 4/4 RED stubs) |
| Full suite | PASS | 3197 passed, 7 pre-existing failures unchanged (AppSidebarKbd x3, DriftAlerts x4) |

---

## Known Stubs

None. All deferred items (install affordance, offline fallback, cache storage validation) are manual UAT items, not code stubs.

---

## Threat Flags

No new security-relevant surfaces outside the plan's threat model. All mitigations confirmed implemented:

| Threat ID | Mitigation Status |
|-----------|------------------|
| T-04-02-01 (SW caches auth HTML) | Mitigated: fetch handler is network-only for navigation; never writes HTML to cache |
| T-04-02-02 (/sw.js, /site.webmanifest, /icons unauthenticated) | Accepted: intentionally public, no financial data |
| T-04-02-03 (stale SW on security patch) | Mitigated: cache name tied to nativephp.version; SW served no-cache, no-store |
| T-04-02-04 (SW intercepts POSTs) | Mitigated: fetch handler returns early for non-GET |

---

## Self-Check: PASSED

- [x] `public/icons/icon-192.png` — exists, PNG 192x192 RGBA
- [x] `public/icons/icon-512.png` — exists, PNG 512x512 RGBA
- [x] `public/icons/icon-512-maskable.png` — exists, PNG 512x512 RGBA
- [x] `public/icons/apple-touch-icon.png` — exists, PNG 180x180 RGB (opaque — correct for iOS)
- [x] `public/site.webmanifest` — exists, valid JSON with "beatrax", "standalone", maskable icon
- [x] `public/offline.html` — exists, contains "You're offline", "Try again", privacy note; no external URLs, no Blade tags
- [x] `resources/views/sw.blade.php` — exists, contains beatrax-shell-v, clients.claim(), skipWaiting(), /livewire /api /desktop exclusions
- [x] `routes/web.php` — contains /sw.js, /site.webmanifest, /icons routes
- [x] `resources/views/layouts/app.blade.php` — viewport-fit=cover (1 occurrence), manifest link, dual theme-color, apple-touch-icon, serviceWorker.register('/sw.js')
- [x] `Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php` — sw, site.webmanifest, pwa.icon in EXEMPT_ROUTE_PREFIXES
- [x] Task 1 commit `a9aef9d` — confirmed in git log
- [x] Task 2 commit `2dd7506` — confirmed in git log
- [x] Task 3 commit `14c2d92` — confirmed in git log
