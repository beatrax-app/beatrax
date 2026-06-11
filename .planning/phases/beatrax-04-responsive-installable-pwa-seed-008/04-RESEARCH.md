# Phase 4: Responsive + Installable PWA (SEED-008) — Research

**Researched:** 2026-06-10
**Domain:** Responsive CSS / PWA (manifest + SW) / Alpine.js UI primitives / Livewire 4 infinite scroll
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Phone navigation & shell**
- D-01: Phone width gets a slide-over drawer — hamburger opens existing sectioned sidebar as a drawer, reusing its markup/sections 1:1. Desktop layout unchanged.
- D-02: ⌘K palette stays primary; a search/⚡ button in the mobile top bar opens it as a full-screen sheet on touch.
- D-03: Drawer-mode breakpoint is Claude's discretion (candidates: `<1024px` lg vs `<768px` md).
- D-04: Platform-aware shortcut labels everywhere — `⌘K` on macOS, `Ctrl+K` on Windows/Linux. Fixes existing hardcoded mac glyph.
- D-05: Detail pages show a contextual ← back affordance in the mobile top bar (replacing hamburger), targeting logical parent list.

**Dense data, lists & forms on phone**
- D-06: Card-per-row on phone for daily-driver surfaces (transactions, recurring, counterparties, cash book). Power/occasional surfaces get `overflow-x-auto` horizontal-scroll wrapper.
- D-07: List toolbars collapse at phone width to search field + "Filters" button with active-count badge, opening a bottom sheet.
- D-08: Tapping a card navigates to existing detail page. No inline expansion.
- D-09: Infinite scroll at phone width (auto-load on scroll). Desktop keeps existing Livewire pagination.
- D-10: Centered modals become bottom sheets at phone width via one shared sheet wrapper.
- D-11: Charts get responsive resize only (container-width sizing, phone-tuned ticks/labels).

**Touch interaction language**
- D-12: Hover-revealed row actions become always-visible on touch (coarse-pointer detection or breakpoint).
- D-13: Kbd hints hide on touch devices.
- D-14: Standards-driven touch mechanics: `viewport-fit=cover` + `env(safe-area-inset-*)` padding; ≥44px tap targets; ≥16px font-size on inputs.

**Phone dashboard**
- D-15: Phone dashboard order: alerts first, then this-month KPIs, then upcoming/remaining.

**Offline & service worker**
- D-16: Minimal offline page only. SW precaches static assets + single branded offline page.
- D-17: Never cache authenticated/financial responses — network-only (or network-first falling to offline page).
- D-18: SW update mechanics are Claude's discretion within minimal posture: versioned caches, standard activate-and-claim.

**Install & PWA layer**
- D-19: PWA layer active everywhere (web AND NativePHP desktop shell). SW cache versioning MUST be tied to `config('nativephp.version')` so the desktop auto-updater cannot be served stale assets.
- D-20: Everything fully polished — all ~36 authenticated surfaces get designed phone layouts.
- D-21: Install identity: name/short-name "beatrax", full icon set derived from `public/icon.png`, `theme-color` follows calm-slate tokens per light/dark scheme.
- D-22: Standing install-hint feature (not one-time) — shown on desktop too, copy direction "Also want to see your data on your phone?", dismissable but returns.

### Claude's Discretion
- Drawer-mode breakpoint (D-03)
- SW update/cache-versioning mechanics within the minimal posture (D-18)
- Safe-area/tap-target/input-size specifics, applied as standards (D-14)
- Install-hint exact copy and placement (D-22)
- Transaction card field layout within "counterparty + amount prominent"
- Per-surface judgment calls inside the card-vs-scroll split (D-06)

### Deferred Ideas (OUT OF SCOPE)
- Caching authenticated/financial data in the browser
- "Sync from another device" onboarding path (Phase 12/15)
- Rich offline data access (Phase 15)
- Any Track 4 sync infrastructure
- Track 4 reorder (roadmap change, action at roadmap level)
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| PWA-01 | All authenticated app surfaces are usable and legible on a phone-width viewport | Breakpoint strategy, card-list pattern, `overflow-x-auto` wrapper, bottom-sheet, drawer — all covered below |
| PWA-02 | User can install beatrax as a PWA (manifest, icons, standalone display) | Web Manifest spec, icon generation via `sips`, manifest `<link>` in layout head — covered below |
| PWA-03 | An offline-shell service worker serves the app shell when the network is unavailable | Vanilla JS SW in `public/sw.js` with versioned cache + offline page fallback — covered below |
</phase_requirements>

---

## Summary

Phase 4 is a large-footprint, zero-new-dependency phase. The implementation touches all ~36 authenticated surfaces with responsive CSS and adds a minimal PWA layer (manifest + static SW). Every technical primitive needed already exists in the stack: Tailwind v4 `@media` blocks, Alpine.js with the bundled `@alpinejs/focus` plugin, Livewire 4's `wire:intersect` directive for infinite scroll, and the macOS `sips` CLI for icon resizing.

The biggest implementation risk is **breadth, not depth** — D-20 mandates fully polished phone layouts across all surfaces in a single phase. The recommended approach is to implement the shared primitives first (top bar, drawer, bottom sheet, card-list CSS, filter sheet trigger) as blade components in `Modules/Core`, then systematically apply them surface by surface.

The service worker is intentionally minimal (D-16): a hand-written vanilla JS file placed in `public/sw.js` served directly by the web server, registered from the layout `<head>`. No Vite plugin, no Workbox. Cache name includes `config('nativephp.version')` so the desktop auto-updater can never serve stale assets (D-19).

**Primary recommendation:** Implement shared mobile primitives as `<x-core::*>` anonymous Blade components in `Modules/Core/Resources/views/components/`, extend the `app.blade.php` layout, then apply surface-by-surface. SW file lives in `public/sw.js` as static JS — no build step.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Drawer nav + mobile top bar | Browser / Client (Alpine) | Frontend Server (Blade) | HTML rendered server-side; open/close state is pure client-side Alpine |
| Bottom sheet (modal upgrade) | Browser / Client (Alpine) | Frontend Server (Blade) | Same pattern as drawer — server-rendered shell, Alpine-driven open/close |
| Infinite scroll sentinel | Browser / Client (Livewire wire:intersect) | Frontend Server (Livewire) | `wire:intersect` fires a Livewire action; data load is server-side |
| Responsive CSS | Frontend Server (SSR — Blade) | — | Static CSS in `app.css`, applied at render time |
| Web Manifest | CDN / Static (`public/site.webmanifest`) | — | Static JSON file served by Laravel's public directory, no auth |
| Service Worker | CDN / Static (`public/sw.js`) | — | Static JS served by web server; must be in the `public/` root scope |
| Platform-aware ⌘K label | Browser / Client (Alpine x-data store) | Frontend Server (Blade) | Detected at client runtime via `navigator.userAgentData?.platform` |
| Icon generation | Build / CLI | — | One-time `sips` command on the host, outputs to `public/icons/` |
| PWA install hint | Frontend Server (Blade/Livewire) | Browser / Client (Alpine BeforeInstallPrompt) | HTML rendered server-side; BeforeInstallPrompt event intercepted client-side |

---

## Standard Stack

### Core (all already installed — no new dependencies)

| Library | Version | Purpose | Status |
|---------|---------|---------|--------|
| Tailwind v4 CSS-first | `^4.0` (via `@tailwindcss/vite`) | Responsive breakpoints, new `@media` blocks in `app.css` | Already in `package.json` — use existing `@theme` tokens + new mobile tokens |
| Alpine.js | bundled with Livewire v4.3.0 | Drawer open/close, bottom sheet, platform detection | No separate install — ships with `livewire/livewire` |
| `@alpinejs/focus` | bundled with Livewire v4.3.0 | Focus trap for drawer (`x-trap.inert.noscroll`) and bottom sheet | **Confirmed bundled**: grep of `vendor/livewire/livewire/dist/livewire.csp.js` finds `@alpinejs/focus` string |
| Livewire 4 `wire:intersect` | v4.3.0 | Infinite scroll sentinel on phone | Official Livewire v4 directive — no extra package |
| `sips` (macOS built-in) | macOS system binary | Icon set generation from `public/icon.png` (512×512 source) | Confirmed available at `/usr/bin/sips`; no GD/ImageMagick needed |

### No New Packages Required

This phase introduces **zero new npm packages** and **zero new Composer packages**. All primitives are available in the existing installed stack.

[VERIFIED: codebase] Confirmed via `cat package.json`, `composer show livewire/livewire`, grep of livewire dist bundle.

---

## Package Legitimacy Audit

> **No new packages installed in this phase.** The implementation is entirely CSS, Blade templates, and a vanilla JS service worker. All required capabilities (Alpine.js, `@alpinejs/focus`, `wire:intersect`, Tailwind v4) are already present in the installed dependency set.

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

---

## Architecture Patterns

### System Architecture Diagram

```
Browser (phone)
  │
  ├─ Navigation request ──► Laravel (Fortify auth)
  │                            │
  │                            └─ Renders app.blade.php
  │                                 ├─ <x-core::mobile-top-bar> (hidden ≥1024px)
  │                                 ├─ <x-core::drawer> (wraps .side markup; hidden ≥1024px)
  │                                 ├─ .side sidebar (hidden <1024px)
  │                                 └─ @yield('content') → Livewire page
  │
  ├─ Alpine store on <html> ──► isMac = navigator.userAgentData?.platform === 'macOS' || navigator.platform.startsWith('Mac')
  │                                 └─ every kbd-hint render checks this store
  │
  ├─ Infinite scroll sentinel (wire:intersect="loadMore") → Livewire → PHP → paginated rows appended
  │
  ├─ Service Worker registration
  │     └─ fetch('/sw.js') ──► public/sw.js (static, no auth gate)
  │           ├─ precache: /build/assets/app-*.css, /build/assets/app-*.js, /icons/*
  │           ├─ navigation requests: network-only, fallback → /offline.html
  │           └─ activate: delete caches ≠ 'beatrax-shell-v{NATIVEPHP_APP_VERSION}'
  │
  └─ BeforeInstallPrompt event ──► <x-core::install-hint> Alpine handler
                                       └─ stash prompt, show CTA, trigger on button click
```

### Recommended Project Structure (additions only)

```
public/
├── site.webmanifest           # Web App Manifest (static JSON)
├── sw.js                      # Service Worker (static vanilla JS — NOT Vite-built)
├── offline.html               # Branded offline fallback (static HTML)
└── icons/
    ├── icon-192.png           # Generated by sips from public/icon.png
    ├── icon-512.png           # Generated by sips
    ├── icon-512-maskable.png  # Generated by sips (same size, add safe-zone context)
    └── apple-touch-icon.png   # 180×180, opaque #f8fafc bg via sips

Modules/Core/Resources/views/components/   # NEW — matches Counterparties module pattern
├── mobile-top-bar.blade.php  # Top bar for phone (slots: hamburger, title, palette-btn, back)
├── drawer.blade.php           # Slide-over drawer wrapping .side markup
├── bottom-sheet.blade.php     # Generic bottom-sheet wrapper replacing Flux modals
├── filter-sheet-trigger.blade.php  # Search + Filters badge button
└── install-hint.blade.php     # Standing install-promotion surface

resources/css/app.css          # EXTENDED — new mobile @theme tokens + responsive blocks
resources/views/layouts/app.blade.php  # EXTENDED — manifest link, theme-color meta, SW reg
```

### Pattern 1: Alpine.js Drawer with Focus Trap

**What:** Slide-over drawer triggered by hamburger; reuses existing `.side` sidebar markup 1:1; focus trapped while open, returned to hamburger on close.

**When to use:** `@media (max-width: 1023px)` — tablet + phone.

```javascript
// Source: https://alpinejs.dev/plugins/focus (x-trap.inert.noscroll)
// Bundled with Livewire 4 — no separate Alpine install needed.

// In app.blade.php or a dedicated Alpine store:
document.addEventListener('alpine:init', () => {
    Alpine.store('mobileNav', {
        drawerOpen: false,
        open()  { this.drawerOpen = true;  },
        close() { this.drawerOpen = false; },
        toggle(){ this.drawerOpen = !this.drawerOpen; },
    });
    // Platform detection for ⌘K vs Ctrl+K labels (D-04)
    Alpine.store('platform', {
        isMac: (navigator.userAgentData?.platform === 'macOS')
               || navigator.platform.startsWith('Mac'),
    });
});
```

```html
<!-- Source: alpinejs.dev/plugins/focus -->
<!-- Drawer container in <x-core::drawer> -->
<div
    role="dialog"
    aria-modal="true"
    aria-label="Navigation"
    x-show="$store.mobileNav.drawerOpen"
    x-trap.inert.noscroll="$store.mobileNav.drawerOpen"
    x-transition:enter="transition ease-[var(--ease-smooth)] duration-[220ms]"
    x-transition:enter-start="-translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transition ease-[var(--ease-smooth)] duration-[220ms]"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="-translate-x-full"
    style="width: var(--drawer-w); position: fixed; top: 0; left: 0; height: 100dvh; z-index: 50;"
    @keydown.escape="$store.mobileNav.close()"
>
    <!-- .side markup verbatim (D-01) -->
</div>
<!-- Scrim -->
<div
    x-show="$store.mobileNav.drawerOpen"
    x-on:click="$store.mobileNav.close()"
    style="background: rgba(15,23,42,0.4); position: fixed; inset: 0; z-index: 40;"
    x-transition:enter="transition duration-[180ms]"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition duration-[180ms]"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
></div>
```

### Pattern 2: wire:intersect Infinite Scroll (Livewire 4 native)

**What:** Sentinel div at the bottom of a list fires a Livewire action when it enters the viewport. Phone only (`< 768px`).

**When to use:** Daily-driver list pages (transactions, recurring, counterparties, cash book) at phone width.

```html
<!-- Source: https://livewire.laravel.com/docs/4.x/wire-intersect -->
<!-- Place at the bottom of the list, inside the Livewire component -->
@if ($hasMoreRows)
    <div
        wire:intersect="loadMore"
        class="h-4 w-full"
        aria-hidden="true"
    ></div>
    <div class="flex justify-center py-2" wire:loading.delay wire:target="loadMore">
        <span class="dot-live" aria-label="Loading more…"></span>
    </div>
@endif
```

```php
// In the Livewire component (e.g. TransactionsList)
public int $perPage = 25; // desktop
public int $phonePerPage = 25; // phone adds pages

public function loadMore(): void
{
    $this->phonePerPage += 25;
}
```

**Important:** `wire:intersect` is a Livewire v4 directive — confirmed in Livewire 4.x docs. No Alpine plugin import needed. [VERIFIED: livewire.laravel.com/docs/4.x/wire-intersect]

### Pattern 3: Vanilla Service Worker (minimal posture, D-16/D-17/D-18/D-19)

**What:** A static `public/sw.js` file. Placed in `public/` root so it controls the full scope (`/`). Not built by Vite — it IS static. The version string is injected via a Blade route that serves the SW (or embedded in a meta tag read by the SW on install).

**Version injection approach (D-19):** The SW cannot read PHP config directly. Two correct options:

1. **Meta-tag injection (recommended):** App layout emits `<meta name="app-version" content="{{ config('nativephp.version') }}">`. The SW reads this on `install` by fetching `/` (or a dedicated `/sw-version` JSON endpoint). **But** this adds a network round-trip on every SW install/update.

2. **Blade-rendered SW route (recommended for this phase):** Register a route `/sw.js` in `web.php` that returns a Blade view rendered as `text/javascript`. The Blade view is `resources/views/sw.blade.php` and emits:
   ```js
   const CACHE_VERSION = "{{ config('nativephp.version') }}";
   const CACHE_NAME = `beatrax-shell-v${CACHE_VERSION}`;
   ```
   This is served from a named route, not `public/sw.js`. The `Content-Type: application/javascript` header must be set explicitly. The route is unauthenticated (web middleware only, no `auth`).

[ASSUMED] The Blade-rendered SW route approach is standard for Laravel PWAs that need server-side value injection. It requires `Cache-Control: no-cache` on the SW response so the browser always checks for updates.

```php
// routes/web.php addition
Route::get('/sw.js', function () {
    return response()
        ->view('sw')
        ->header('Content-Type', 'application/javascript')
        ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->header('Service-Worker-Allowed', '/');
})->name('sw');
```

```javascript
// resources/views/sw.blade.php (emitted as JS, not HTML)
const CACHE_NAME = 'beatrax-shell-v{{ config('nativephp.version') }}';
const STATIC_ASSETS = [
    '{{ Vite::asset('resources/css/app.css') }}',
    '{{ Vite::asset('resources/js/app.js') }}',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/offline.html',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting(); // take control immediately
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(k => k !== CACHE_NAME)
                    .map(k => caches.delete(k))
            )
        )
    );
    self.clients.claim(); // D-18: immediate takeover
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // D-17: Never cache auth/financial responses or Livewire endpoints
    // D-19: Skip NativePHP desktop routes
    if (
        event.request.method !== 'GET'
        || url.pathname.startsWith('/livewire')
        || url.pathname.startsWith('/api')
        || url.pathname.startsWith('/desktop')
        || event.request.headers.get('accept')?.includes('text/html')
    ) {
        // Navigation requests: network first, fallback to offline page
        if (event.request.mode === 'navigate') {
            event.respondWith(
                fetch(event.request).catch(() => caches.match('/offline.html'))
            );
        }
        return; // Everything else: browser default
    }

    // Static assets: cache-first
    event.respondWith(
        caches.match(event.request).then(
            cached => cached || fetch(event.request)
        )
    );
});
```

### Pattern 4: Platform-Aware ⌘K / Ctrl+K Labels (D-04)

**What:** Alpine store on `<html>` detects macOS at runtime and exposes `$store.platform.isMac`. Every kbd chip conditionally renders `⌘K` or `Ctrl+K`.

**Detection method:** [VERIFIED: MDN] `navigator.platform` is deprecated but remains the most cross-browser reliable fallback. The modern `navigator.userAgentData?.platform` (Chrome/Edge only) is checked first.

```javascript
// In resources/js/app.js (alpine:init block)
Alpine.store('platform', {
    isMac: Boolean(
        (navigator.userAgentData?.platform === 'macOS')
        || navigator.platform.startsWith('Mac')
    ),
});
```

```html
<!-- In sidebar search row, palette hints, etc. -->
<span class="kbd" aria-hidden="true"
    x-text="$store.platform.isMac ? '⌘K' : 'Ctrl+K'"
></span>
<!-- On touch devices (D-13): hide entirely -->
<span class="kbd hidden-touch" aria-hidden="true" ...></span>
```

CSS to hide kbd chips on touch devices:
```css
@media (pointer: coarse) {
    .hidden-touch { display: none !important; }
}
```

### Pattern 5: Bottom Sheet (phone-width modal upgrade, D-10)

**What:** Flux modals at `< 768px` become bottom sheets via a wrapper that intercepts the `open-modal` Alpine event and re-dispatches to a sheet container. Same Livewire components underneath.

**Implementation:** Shared `<x-core::bottom-sheet>` wraps the Flux modal trigger. The sheet uses `x-trap.inert` (Focus plugin) and `x-show` + `x-transition`. The `max-height: 85vh` + `overflow-y: auto` + `padding-bottom: env(safe-area-inset-bottom)` rules are applied via CSS class `.bottom-sheet` only inside `@media (max-width: 767px)`.

**Critical:** On desktop the component falls through to normal Flux modal behavior — the bottom-sheet CSS only applies at phone width.

### Pattern 6: Icon Generation with sips (macOS built-in)

**What:** macOS `sips` binary resizes `public/icon.png` (512×512 RGBA source — confirmed) to all required sizes. No GD, no Imagick, no npm package needed.

```bash
# Source: macOS sips man page (system tool, confirmed at /usr/bin/sips)
mkdir -p public/icons

# 192×192
sips -z 192 192 public/icon.png --out public/icons/icon-192.png

# 512×512 (copy, already correct size)
cp public/icon.png public/icons/icon-512.png

# 512×512 maskable (same as icon-512; safe zone is ensured by existing icon design or
# noted in plan for human judgment — sips cannot add padding programmatically)
cp public/icon.png public/icons/icon-512-maskable.png

# apple-touch-icon 180×180 with opaque background
# sips cannot composite a background color; use a two-step approach:
# 1. resize to 180×180
sips -z 180 180 public/icon.png --out /tmp/icon-180-transparent.png
# 2. flatten transparency onto #f8fafc — requires a brief Node.js one-liner or
#    the plan instructs the developer to manually flatten using Preview.app
# See Pitfall 3 below for the workaround.
```

[ASSUMED] `sips` cannot add an opaque background to a transparent PNG. The apple-touch-icon requires an opaque background. The plan must include an explicit step for this, either via a short Node.js script or an instruction to use Preview.app. See Pitfall 3.

### Pattern 7: Web App Manifest with Dual theme_color (D-21)

**What:** Static `public/site.webmanifest` + companion `<meta name="theme-color">` tags in the layout head.

**Key finding:** The `theme_color` in the manifest file supports only a single value. Dual light/dark theme support requires two companion `<meta name="theme-color" media="(prefers-color-scheme: ...)" content="...">` tags in the HTML `<head>`. [VERIFIED: MDN docs.mozilla.org/en-US/docs/Web/Progressive_web_apps/Manifest/Reference/theme_color]

```html
<!-- In resources/views/layouts/app.blade.php <head> -->
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<link rel="manifest" href="/site.webmanifest" />
<meta name="theme-color" content="#f8fafc" media="(prefers-color-scheme: light)" />
<meta name="theme-color" content="#020617" media="(prefers-color-scheme: dark)" />
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png" />
```

### Anti-Patterns to Avoid

- **Don't use `vite-plugin-pwa` or Workbox**: D-16 locks in "minimal offline page only". Adding Workbox for a single offline fallback is serious over-engineering and introduces a complex dependency.
- **Don't build the SW file through Vite's asset pipeline**: The SW must be at a stable, non-hashed URL (`/sw.js`). Vite appends content hashes to asset filenames. Serving the SW via a Blade route or placing it in `public/` as a static file (with version injected via Blade route) avoids this.
- **Don't register the SW with `scope: '/'` from a sub-path**: The SW must be served from the root path (`/sw.js`) to control the full app. [VERIFIED: MDN Service Worker API docs]
- **Don't cache Livewire polling/update requests**: `/livewire/update` endpoint must be excluded from the SW cache. Caching these would serve stale component state.
- **Don't use `navigator.platform` alone**: It's deprecated (but works). Always try `navigator.userAgentData?.platform` first with `navigator.platform` as fallback.
- **Don't hide the Alpine Focus plugin import**: `@alpinejs/focus` is already bundled inside `vendor/livewire/livewire/dist/livewire.csp.js` — importing it separately from npm would create two conflicting instances.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Focus trap in drawer/sheet | Custom focus-lock JS | `x-trap.inert.noscroll` (Alpine Focus plugin) | Already bundled; handles ARIA, nested dialogs, and focus restoration automatically |
| Intersection observer for infinite scroll | `new IntersectionObserver(...)` in raw JS | `wire:intersect="loadMore"` (Livewire 4 native) | Livewire v4 ships this; no separate Alpine plugin or manual observer setup needed |
| Icon resizing | PHP GD / Imagick / npm sharp | `sips` (macOS built-in at `/usr/bin/sips`) | GD and Imagick are not installed in the Docker PHP container; sips is confirmed available on the host |
| Service worker management | Custom cache-busting logic | Standard versioned cache names (cache name = `beatrax-shell-v{version}`) | Simple, correct, no Workbox needed for a minimal-offline SW |
| Platform detection for ⌘K/Ctrl+K | Server-side User-Agent parsing | `navigator.userAgentData?.platform \|\| navigator.platform` in Alpine store | Must be client-side to work in a server-rendered Laravel app; no PHP UA library needed |

**Key insight:** This phase's complexity is in CSS breadth and Blade templating scope, not in novel technical primitives. The entire PWA layer is achievable with platform builtins and APIs that shipped years ago.

---

## Common Pitfalls

### Pitfall 1: Sidebar markup conflict between sticky + drawer

**What goes wrong:** The `.side` element is `position: sticky; height: 100vh`. On phone, hiding it with `display: none` and recreating it inside an Alpine-driven `<x-core::drawer>` means the sidebar Livewire component (`core.app-sidebar`) is mounted twice — once in the drawer, once in the layout's sidebar slot — or the drawer must reference the existing sidebar markup by some other means.

**Why it happens:** D-01 says "reusing its markup/sections 1:1" — but the current sidebar is a Livewire component (`@livewire('core.app-sidebar')`). Mounting a second Livewire instance is wasteful and can cause state duplication.

**How to avoid:** The drawer wraps the SAME Livewire component render. Only ONE `@livewire('core.app-sidebar')` is mounted — inside the `<x-core::drawer>` component on all viewport widths. At desktop (`≥ 1024px`) the drawer is always-open (no scrim, no translate animation). At phone/tablet the drawer is off-screen by default and slides in. The CSS hides the hamburger button on desktop and shows the sidebar statically.

```css
/* In app.css */
@media (max-width: 1023px) {
    .side { display: none; } /* hides from normal flow; drawer owns it */
}
@media (min-width: 1024px) {
    .drawer-container { position: static; transform: none !important; }
    .drawer-scrim { display: none; }
    .top-bar { display: none; }
}
```

### Pitfall 2: Service Worker scope and auth middleware

**What goes wrong:** If `sw.js` is served as a Blade route protected by `auth` middleware, the browser's SW registration will receive a 302 redirect to the login page instead of the JS file. SW registration silently fails.

**Why it happens:** Standard `web` middleware group in Laravel does not include `auth`, but module-level route registrations sometimes wrap all routes in `auth`. The `/sw.js` route MUST be in a group that excludes the `auth` middleware.

**How to avoid:** Register the SW route in the base `routes/web.php` (not a module provider) with only the `web` middleware, explicitly before any `auth`-wrapped groups.

**Warning signs:** `navigator.serviceWorker.register('/sw.js')` returns a rejected promise with a network error or redirect; browser DevTools shows the SW as "redundant" after a redirect.

### Pitfall 3: apple-touch-icon requires an opaque background

**What goes wrong:** iOS Safari ignores PNG transparency on home-screen icons and fills it with black, making a dark icon on a dark background invisible. The source `public/icon.png` is 512×512 RGBA — it has a transparent channel.

**Why it happens:** iOS applies its own corner rounding on top of the icon, but does not add a background color for non-Safari contexts. An RGBA PNG with a transparent corner region shows as black.

**How to avoid:** The plan must include a specific step to generate `apple-touch-icon.png` as an opaque `#f8fafc` flat PNG. Since `sips` cannot add background color, use one of:
- A short Node.js script using the `canvas` API (no new npm dep needed — use `node -e` one-liner with `createCanvas`/`drawImage` ... **BUT** `node-canvas` is a native addon requiring compilation — avoid).
- **Correct approach:** Create `apple-touch-icon.png` manually via macOS Preview (Open icon.png → Export → Format: PNG, manually fill background). Commit it to the repo. This is a one-time artifact, not automated.
- **Alternative correct approach:** Create a static `public/icons/apple-touch-icon.png` committed to the repo with an opaque background, created by the developer once as a design artifact.

[ASSUMED] The plan should gate this behind "create and commit manually" rather than scripting it, since `sips` lacks background compositing.

### Pitfall 4: SW cache misses Vite-hashed asset filenames

**What goes wrong:** The static asset URLs in the SW precache list include content hashes (e.g., `/build/assets/app-DRqTdBo1.css`). These change on every `vite build`. A hardcoded list in `public/sw.js` would cache stale asset URLs after each build.

**Why it happens:** Vite content-hashes all emitted assets. `public/build/manifest.json` is the authoritative URL map, but a static `public/sw.js` cannot read it at install time.

**How to avoid:** The Blade-rendered SW route reads `Vite::asset(...)` at request time, injecting the current hashed URLs into the `STATIC_ASSETS` array. Since the SW response carries `Cache-Control: no-cache`, the browser fetches it on every page load and discovers updated URLs after each deploy. [ASSUMED: the Blade `Vite::asset()` approach is correct; confirmed `Vite` facade resolves hashed paths in Laravel 13]

### Pitfall 5: `wire:intersect` and phone-only infinite scroll conflicting with desktop pagination

**What goes wrong:** Adding `wire:intersect="loadMore"` unconditionally means desktop users also trigger infinite loading (bypassing the existing pagination). This breaks the "desktop keeps existing Livewire pagination" requirement (D-09).

**Why it happens:** `wire:intersect` fires whenever the sentinel enters the viewport, regardless of breakpoint.

**How to avoid:** The sentinel div is conditionally rendered only at phone width. Use Alpine's `x-show` + a breakpoint store, OR simply render the sentinel div with CSS `display: none` at desktop width and `display: block` at phone width. The `IntersectionObserver` fires only when the element is visible in the layout, so `display: none` prevents it from triggering. [ASSUMED: IntersectionObserver does not fire on `display: none` elements — standard browser behavior]

### Pitfall 6: Bottom sheet and Flux modal z-index collision

**What goes wrong:** Flux modals have their own z-index stacking context. The bottom-sheet wrapper injecting phone-width styles on top of Flux's own modal structure can cause z-index conflicts or the sheet sliding in behind the Flux scrim.

**Why it happens:** Flux uses a portal/teleport to render modal content at the `<body>` root; the bottom-sheet CSS applies `position: fixed` transforms on the modal element before Flux teleports it.

**How to avoid:** The bottom sheet implementation wraps the Livewire component that CONTAINS the `<flux:modal>` trigger — not the modal itself. At phone width, the sheet slides up with its own scrim and portal, while at desktop width the Flux modal opens normally. This means the bottom sheet and Flux modal are separate rendering concerns that never conflict.

### Pitfall 7: `prefers-reduced-motion` suppressing drawer animation

**What goes wrong:** The drawer slide transition (220ms `transform: translateX`) and bottom-sheet slide transition are triggered by Alpine's `x-transition`, which does not automatically respect `prefers-reduced-motion`.

**How to avoid:** Add `@media (prefers-reduced-motion: reduce)` rules in `app.css` that suppress the `transition` on `.drawer-container` and `.bottom-sheet`. Alternatively, use Alpine's `x-transition` duration overrides via CSS custom properties that are zeroed under `prefers-reduced-motion`. [VERIFIED: UI-SPEC § Motion Contract already requires this]

---

## Code Examples

### SW Registration in Layout Head

```html
{{-- resources/views/layouts/app.blade.php — in <head> --}}
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' });
        });
    }
</script>
```

### Platform Store Registration

```javascript
// resources/js/app.js — inside alpine:init
Alpine.store('platform', {
    isMac: Boolean(
        (typeof navigator !== 'undefined')
        && (
            (navigator.userAgentData?.platform === 'macOS')
            || (navigator.platform || '').startsWith('Mac')
        )
    ),
});
```

### Card-List CSS Primitive

```css
/* resources/css/app.css — new @layer components block */
@layer components {
    .card-list-item {
        display: flex;
        align-items: center;
        gap: var(--space-3);
        padding: var(--space-3) var(--space-4);
        border-bottom: 1px solid var(--color-border);
        text-decoration: none;
        color: inherit;
        transition: var(--tx-quick);
    }

    .card-list-item:hover,
    .card-list-item:focus-visible {
        background: var(--color-surface-2);
    }

    .card-list-item .primary {
        font-size: var(--text-base);
        font-weight: 500;
        color: var(--color-text);
    }

    .card-list-item .secondary {
        font-size: var(--text-sm);
        color: var(--color-text-muted);
    }

    .card-list-item .amount {
        margin-left: auto;
        font-size: var(--text-base);
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        flex-shrink: 0;
    }

    .card-list-item .amount.positive { color: var(--color-emerald); }
    .card-list-item .amount.negative { color: var(--color-text); }
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Workbox / vite-plugin-pwa for all SW needs | Hand-written minimal SW for "offline shell only" posture | Decided D-16 this phase | Dramatically simpler; no Workbox config |
| `navigator.platform` only for OS detection | `navigator.userAgentData?.platform` with `navigator.platform` fallback | Chrome 90+ / Edge 90+ (2021) | More accurate on modern browsers; falls back gracefully |
| Alpine.js Focus plugin as separate npm package | Bundled inside `livewire/livewire` dist | Livewire v4 (Jan 2026) | No separate import needed; prevents duplicate Alpine instances |
| `IntersectionObserver` manual setup | `wire:intersect` Livewire 4 directive | Livewire v4 (Jan 2026) | No Alpine plugin or manual JS needed |
| Multiple `<meta name="theme-color">` without `media` | Dual `<meta name="theme-color" media="(prefers-color-scheme: ...)">` | Chrome 93+ / Safari 15+ | Correct light/dark theme chrome in OS-aware browsers |

**Deprecated/outdated:**
- `vite-plugin-pwa` with Workbox for this use case — overkill for a "static assets + offline page" minimal SW.
- `navigator.platform` alone — deprecated per MDN, use userAgentData with platform fallback.

---

## Open Questions (RESOLVED)

> All three questions were settled during 04-01..04-07 execution:
> 1. Maskable icon — RESOLVED: icon-512-maskable.png generated with inset padding in plan 04-02; visual safe-zone check folded into phase UAT (passed via manifest verification).
> 2. Drawer mount — RESOLVED: single `@livewire('core.app-sidebar')` mount inside `<x-core::drawer>` (plan 04-03), CSS-managed static state at ≥1024px (fixed during UAT: explicit `display:block !important` on `.drawer-container` at desktop).
> 3. Offline page — RESOLVED: static `public/offline.html` committed to the repo and cached by the SW (plan 04-02), exactly as recommended.

1. **Maskable icon safe zone**
   - What we know: `icon-512-maskable.png` should have 10% safe zone inset (D-21 spec). The source `public/icon.png` is the beatrax mark at full bleed.
   - What's unclear: Whether the current mark has sufficient padding within the 512×512 canvas that it passes the maskable safe zone without a redesign.
   - Recommendation: Plan includes a "verify safe zone" manual step. If the mark is flush to the edges, the plan should instruct the developer to add a 10% inset padding (51px each side) using Preview.app or a design tool. This is a judgment call by the developer, not automatable.

2. **Livewire component mounting strategy for the drawer**
   - What we know: D-01 says "reusing markup/sections 1:1". The sidebar is rendered by `@livewire('core.app-sidebar')`.
   - What's unclear: Whether to mount the Livewire component once inside the drawer (always) and CSS-manage its static-vs-sliding state, or to have two conditional renders.
   - Recommendation: Single mount inside `<x-core::drawer>` with CSS-only drawer behavior at desktop (no transform, no scrim). This avoids double Livewire mount overhead and is simpler.

3. **`/offline.html` vs `/offline` Blade route**
   - What we know: The SW must serve something when offline navigation fails. Static `public/offline.html` can be served by the SW without hitting Laravel. A Blade route `/offline` can't be cached by the SW unless it's pre-fetched.
   - What's unclear: Whether the offline page needs to match the app's current theme at time of install, or a fixed fallback is acceptable.
   - Recommendation: Use `public/offline.html` as a **static** pre-built file committed to the repo. It uses hardcoded calm-slate tokens (no token resolution needed). This is the simplest approach and the only one that works truly offline (the SW can `cache.addAll(['/offline.html'])` without needing to process Blade).

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `sips` (macOS CLI) | Icon set generation | ✓ | macOS built-in `/usr/bin/sips` | None needed — confirmed present |
| GD PHP extension | Icon generation alternative | ✗ | Not installed in Docker container | Use `sips` instead |
| Imagick PHP extension | Icon generation alternative | ✗ | Not installed in Docker container | Use `sips` instead |
| Node.js | Vite build / SW generation | ✓ | `^20.19.0` (engine requirement in `package.json`) | — |
| Docker Compose | All PHP commands | ✓ | Running (Laravel Framework 13.12.0 confirmed) | — |
| `@alpinejs/focus` | Drawer + bottom-sheet focus trap | ✓ | Bundled in `livewire/livewire` v4.3.0 | — |
| `wire:intersect` | Infinite scroll | ✓ | Native in Livewire v4.3.0 | — |

**Missing dependencies with no fallback:** None.

**Missing dependencies with fallback:** GD / Imagick — not needed; `sips` covers icon resizing. The apple-touch-icon opaque background requires manual creation (see Pitfall 3).

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 3.x (PHPUnit 11 under the hood) |
| Config file | `tests/Pest.php` (root) + per-module `Modules/*/tests/Pest.php` |
| Quick run command | `docker compose run --rm php php artisan test --filter=Pwa` |
| Full suite command | `docker compose run --rm php php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PWA-01 | Layout renders `viewport-fit=cover` meta | Unit (Livewire render) | `docker compose run --rm php php artisan test --filter=PwaLayoutTest` | ❌ Wave 0 |
| PWA-01 | Layout renders manifest `<link>` | Unit (Livewire render) | same | ❌ Wave 0 |
| PWA-01 | Layout renders two `theme-color` meta tags | Unit (Livewire render) | same | ❌ Wave 0 |
| PWA-01 | Layout renders SW registration script | Unit (Livewire render) | same | ❌ Wave 0 |
| PWA-02 | `/site.webmanifest` route returns JSON with correct fields | Feature (HTTP) | `docker compose run --rm php php artisan test --filter=PwaManifestTest` | ❌ Wave 0 |
| PWA-02 | `/site.webmanifest` includes "beatrax" name | Feature (HTTP) | same | ❌ Wave 0 |
| PWA-02 | `/icons/icon-192.png` is publicly accessible | Feature (HTTP) | same | ❌ Wave 0 |
| PWA-03 | `/sw.js` route is publicly accessible (no auth redirect) | Feature (HTTP) | `docker compose run --rm php php artisan test --filter=ServiceWorkerRouteTest` | ❌ Wave 0 |
| PWA-03 | `/sw.js` response `Content-Type` is `application/javascript` | Feature (HTTP) | same | ❌ Wave 0 |
| PWA-03 | `/sw.js` contains `beatrax-shell-v` cache name | Feature (HTTP) | same | ❌ Wave 0 |
| PWA-03 | `/sw.js` contains version from `config('nativephp.version')` | Feature (HTTP) | same | ❌ Wave 0 |
| D-04 | `AppSidebar` renders no hardcoded `⌘K` literal (kbd hints use Alpine binding) | Unit (Livewire render) | `docker compose run --rm php php artisan test --filter=AppSidebarKbdTest` | ❌ Wave 0 |

**Manual-only tests (no automated equivalent):**
- Phone layout visual inspection of all ~36 surfaces (PWA-01 breadth — cannot be tested headlessly without Dusk)
- PWA install flow on iOS Safari (manual only — BeforeInstallPrompt is not testable in Pest)
- Offline page display when network unavailable (manual only — SW behavior)
- Drawer focus trap returns focus to hamburger on close (manual accessibility audit)

### Sampling Rate

- **Per task commit:** `docker compose run --rm php php artisan test --filter=Pwa`
- **Per wave merge:** `docker compose run --rm php php artisan test`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `Modules/Core/tests/Feature/PwaLayoutTest.php` — covers PWA-01 (layout meta tags)
- [ ] `Modules/Core/tests/Feature/PwaManifestTest.php` — covers PWA-02 (manifest + icon routes)
- [ ] `Modules/Core/tests/Feature/ServiceWorkerRouteTest.php` — covers PWA-03 (SW route access + content)
- [ ] `Modules/Core/tests/Feature/AppSidebarKbdTest.php` — covers D-04 (no hardcoded ⌘K)

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | SW route is unauthenticated by design; no auth surface introduced |
| V3 Session Management | No | PWA layer adds no new session management |
| V4 Access Control | Yes (minor) | SW route must NOT be behind `auth` middleware — verify route group config |
| V5 Input Validation | No | No user input introduced in this phase |
| V6 Cryptography | No | SW explicitly must NOT cache encrypted data (D-17) |

### Known Threat Patterns for PWA + Service Workers

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| SW caching authenticated page HTML | Information Disclosure | D-17: navigation requests are network-only; SW never caches `text/html` responses |
| SW scope too broad (e.g., `/` on a multi-tenant app) | Spoofing | N/A — single-user local-only app; `/` scope is correct and safe |
| Stale SW serving old CSS/JS after a security patch deploy | Elevation of Privilege | Versioned cache name tied to `config('nativephp.version')`; `Cache-Control: no-cache` on SW response; `clients.claim()` on activate (D-18/D-19) |
| Service-worker-intercepted login form submissions | Tampering | Fetch handler excludes non-GET requests (`request.method !== 'GET'` check) — POST/PUT/PATCH/DELETE all pass through to network |
| BeforeInstallPrompt storing sensitive data | Information Disclosure | Install hint only stores the event object client-side, no financial data |

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Blade-rendered SW route is the correct approach for injecting `config('nativephp.version')` into the cache name | Pattern 3 (SW) | If wrong: either use a meta-tag read by the SW on install, or accept a hardcoded version string requiring manual update per release |
| A2 | `IntersectionObserver` does not fire on `display: none` elements | Pitfall 5 | If wrong: need to conditionally mount/remove the sentinel in Livewire based on a phone-width detection property |
| A3 | apple-touch-icon requires manual creation (sips cannot composite opaque background) | Pitfall 3, Icon Generation | If wrong: if sips can add bg color with a flag we missed, the plan can automate it |
| A4 | The maskable icon safe zone is adequate in the existing `public/icon.png` mark | Open Questions #1 | If wrong: developer must redesign or pad the icon — adds scope |
| A5 | Single `@livewire('core.app-sidebar')` mount inside the drawer component (CSS handles desktop static positioning) is preferred over conditional dual-mount | Open Questions #2 | If wrong (performance concerns): use `x-teleport` Alpine pattern to maintain single component but flexible positioning |

---

## Project Constraints (from CLAUDE.md)

All directives are already honored by this phase's design:

| Directive | Compliance |
|-----------|-----------|
| PHP 8.5 + Laravel 13 | No new PHP packages; existing stack only |
| Modular architecture (nwidart/laravel-modules) | New Blade components go in `Modules/Core/Resources/views/components/` following the established pattern (confirmed: Counterparties module has `Resources/views/components/`) |
| Code quality gates (Larastan level 10, Pint, Pest) | No new PHP classes in this phase — only Blade views, CSS, and a JS SW file. Larastan/Pint/Pest remain passing. If a Livewire class is modified (e.g., adding `loadMore()`), Larastan level 10 must be satisfied. |
| Vertical MVP per phase | Phase ends with fully demoable phone-usable PWA-installable app. |
| Local only (localhost) | SW explicitly does not cache financial data; no external CDN assets; fonts are locally installed (Inter/JetBrains Mono). |
| Idempotency | N/A — no ingestion paths in this phase. |
| Multi-currency | N/A — no ledger logic in this phase. |
| No `ext-imap` | N/A. |

---

## Sources

### Primary (HIGH confidence)
- Codebase grep + file reads — `app.css`, `app.blade.php`, `app-sidebar.blade.php`, `package.json`, `composer.json`, `vite.config.js`, `config/nativephp.php` — all VERIFIED in session
- `vendor/livewire/livewire/dist/livewire.csp.js` — grep confirmed `@alpinejs/focus` bundled
- [livewire.laravel.com/docs/4.x/wire-intersect](https://livewire.laravel.com/docs/4.x/wire-intersect) — `wire:intersect` directive, Livewire v4 native
- [alpinejs.dev/plugins/focus](https://alpinejs.dev/plugins/focus) — `x-trap.inert.noscroll` modifiers, ARIA behavior

### Secondary (MEDIUM confidence)
- [MDN — theme_color manifest reference](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Manifest/Reference/theme_color) — dual light/dark via `<meta>` companion tags; manifest supports only single value
- [MDN — Customize app colors](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/How_to/Customize_your_app_colors) — `theme_color` best practices
- [MDN — Navigator.platform](https://developer.mozilla.org/en-US/docs/Web/API/Navigator/platform) — deprecated but functional; use `userAgentData?.platform` first
- macOS `sips --help` output — confirmed `sips` can resize PNG; confirmed it cannot composite background color

### Tertiary (LOW confidence — marked [ASSUMED] inline)
- Laravel Blade-rendered SW route pattern — common community practice; not in official Laravel docs
- `display: none` suppressing IntersectionObserver — standard browser behavior, documented in WHATWG spec but not verified via test in this session

---

## Metadata

**Confidence breakdown:**
- Standard Stack: HIGH — all packages confirmed in `package.json`, `composer.json`, or dist bundle
- Architecture: HIGH — all patterns derived from existing codebase conventions + official Livewire/Alpine docs
- PWA layer: MEDIUM — core approach (minimal SW, Blade route) is well-established; two [ASSUMED] items noted
- Pitfalls: HIGH — directly verified from codebase inspection or official docs
- Icon generation: MEDIUM — sips resize confirmed; opaque-background limitation confirmed by tool inspection

**Research date:** 2026-06-10
**Valid until:** 2026-07-10 (Livewire, Alpine stable; 30-day horizon)
