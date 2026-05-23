---
status: investigating
trigger: "UAT-2 + UAT-3 + symptom A (white block tray icon, tray disappears when main window opens, cannot reopen main window from tray) — prior cycles applied surface fixes that did not solve the architecture problem"
created: 2026-05-23T12:00:00Z
updated: 2026-05-23T12:00:00Z
---

## Current Focus

reasoning_checkpoint:
  hypothesis: |
    Three coupled symptoms (A: white-block icon, B: tray vanishes when
    main window opens, C: cannot re-open window from tray menu) share one
    architectural root cause: the entire tray runs through NativePHP's
    `MenuBar` API, which is a wrapper around the npm `menubar` library.

    - Even with `onlyShowContextMenu(true)`, the right-click menu items
      built via `Menu::route(...)` produce `type=link` entries whose
      compiled Electron click-handler (see
      `nativephp/electron/electron-plugin/dist/server/api/helper/index.js`
      `compileMenu`) returns EARLY when `focusedWindow` is null:
        item.click = (menuItem, focusedWindow, combo) => {
            ...
            if (!focusedWindow) { return; }
            const id = Object.keys(state.windows).find((key) =>
                state.windows[key] === focusedWindow);
            goToUrl(item.url, id);
        };
      Once the main window is closed (`state.windows[id]` is deleted by
      window.js close handler), no window is focused, so the click
      handler does nothing — symptom C.

    - Symptom B: when `onlyShowContextMenu(false)` (the default) we were
      previously landing in the popover-menubar branch which hid on blur.
      The UAT-2 fix flipped to `true`, which lands in the plain-Tray
      branch — but the user reports the tray STILL disappears on macOS.
      That is consistent with the menubar library still mediating the
      lifecycle, or the user's previous test build not actually carrying
      the fix. Either way: removing MenuBar entirely and creating a
      native Electron `Tray` directly in the main process gives us a
      stable, fully-owned tray with no menubar-library coupling.

    - Symptom A: `resources/brand/tray-icon.png` is a 44x44 fully-opaque
      dark PNG (alpha covers the whole frame). When `setTemplateImage(true)`
      is set, macOS uses the alpha channel as silhouette and recolors —
      since alpha is non-zero everywhere, the entire rectangle becomes
      a solid white block on dark menu bars. The asset needs to be a
      monochrome silhouette with transparent background, sized for
      menu-bar use (~22x22 with a 44x44 @2x sibling).

  confirming_evidence:
    - "nativephp/electron/electron-plugin/dist/server/api/helper/index.js — `compileMenu` link-type click returns early if !focusedWindow → tray menu items cannot open the window once it's closed."
    - "nativephp/electron/electron-plugin/dist/server/api/window.js:155-160 — when /window/open is called with an id that already exists, it does `state.windows[id].show(); .focus()` — providing a one-line affordance to re-open from a fresh Electron Tray click handler if we wire it directly."
    - "nativephp/electron/electron-plugin/dist/server/api/window.js:240-248 — close handler does `delete state.windows[id]` so once closed the registry no longer holds the window; a new BrowserWindow must be created via /window/open to bring the main window back. The PHP-side `WindowManager::open('main')` is the canonical path."
    - "Reading resources/brand/tray-icon.png as image: it is a fully-opaque dark image (read as a tiny near-black square thumbnail), so applying `setTemplateImage(true)` paints the whole frame white in dark mode → the reported 'big white block'."
    - "The user explicitly asked to take a step back and fix this properly — confirming the prior surface fixes did not work."
  falsification_test: |
    Apply the new Electron-native Tray (created directly in
    `src/main/index.js` via prebuild patch, not via NativePHP's MenuBar)
    and a properly-sized monochrome template icon. After rebuild + relaunch,
    the user must observe:
      1. Tray icon renders as a recognizable monochrome pictogram (not a
         white block).
      2. Tray icon persists in menu bar across opening/closing the main
         window.
      3. Clicking "Open diederik" from the tray brings the main window
         back even after the X-button has closed it.
    If any of these fails the hypothesis is wrong.
  fix_rationale: |
    1. Replace `MenuBar::create()` with a direct Electron `Tray` instance
       wired in `nativephp/electron/src/main/index.js` (durably patched
       via a prebuild script since `nativephp/` is gitignored).
    2. The Electron Tray's context-menu items call into the NativePHP
       HTTP server's `/window/open` endpoint with the main window
       definition. The existing /window/open handler shows+focuses an
       existing window OR creates a new one — so it works regardless of
       window state.
    3. Drop the now-dead `MenuBar` facade from the carve-out. Keep the
       PHP `TrayMenuBuilder` only as the labeled-three-rows source of
       truth (constants), since the Electron-side patch will hardcode the
       same labels. Or remove TrayMenuBuilder entirely if it becomes
       trivial — leaning towards removal to keep the surface small.
    4. Regenerate `resources/brand/tray-icon.png` as a black-on-transparent
       silhouette at 22x22 (with a 44x44 @2x sibling) derived from the
       woman-with-crown app icon — alpha=0 outside the figure, alpha=1
       inside, RGB=black inside.
  blind_spots:
    - "Cannot remotely visually verify the rebuilt app — user must rebuild + relaunch to confirm."
    - "Need to ensure the new Electron-side Tray creation runs AFTER NativePHP has bootstrapped (so `state.phpPort` and `state.randomSecret` are set, otherwise the menu-item HTTP calls fail). Use `app.on('ready')` + a poll-for-state pattern similar to the existing file-open glue in index.js."
    - "The 'Quit' tray item just calls `app.quit()` directly — no need for HTTP. The 'Open diederik' and 'Scan email now' items hit `/window/open` with the main window's parameters (matching what `WindowManager::open('main')` would have sent). We must ship the same `id`, `url`, dimensions, rememberState=true."

next_action: |
  1. Generate the new tray icon assets (22x22 and 44x44) from public/icon.png
     by extracting silhouette via threshold, with alpha=1 inside figure
     and RGB=#000000 inside. Tool: sips for resize, ImageMagick for
     threshold/silhouette.
  2. Write a new prebuild script
     `scripts/nativephp_inject_persistent_tray.php` that patches
     `nativephp/electron/src/main/index.js` to create an Electron Tray
     directly with click handlers that POST to /window/open via the
     `notifyLaravel`-style infrastructure (or directly call the API
     handler).
  3. Remove `MenuBar::create(...)` from `NativeAppServiceProvider::boot()`.
  4. Update tests + carve-outs.

## Symptoms

expected: |
  - Tray icon renders as a proper macOS template pictogram that adapts
    to light/dark menu bar appearance (D-19).
  - Tray icon persists across the full app lifecycle, especially when the
    main window opens, hides, or closes (D-09 — "keep running in tray").
  - Clicking "Open diederik" in the tray's right-click context menu
    shows / focuses the main window from any state (foreground,
    minimized, hidden, or fully closed via X).
actual: |
  - Tray icon renders as a 44x44 solid white block on macOS dark menu bar.
  - Tray icon vanishes from menu bar the moment the main window opens.
    Closing the window does not restore it.
  - Right-click "Open diederik" does nothing once the main window has
    been closed.
errors: none (visual + interaction regressions; no console errors)
reproduction: |
  1. Build packaged app via `php artisan native:build mac arm64`.
  2. Launch packaged `diederik.app` from /Applications.
  3. Observe symptom A: tray icon renders as a giant white square.
  4. Observe symptom B: as soon as the main window appears the tray
     vanishes (or never showed in the first place depending on timing).
  5. Close the main window via the red X.
  6. Try to right-click the tray icon → choose "Open diederik" → window
     does not return.
started: |
  - Symptom A & B: since plan 15-02 (tray was introduced).
  - Symptom C: always (was masked by symptom B until the UAT-2 fix
    landed and the user could actually click the tray).

## Eliminated

(prior cycles — kept here so we don't loop back into them)

- hypothesis: "Setting onlyShowContextMenu(true) fully solves symptom B."
  evidence: "Applied in prior UAT-2 cycle. User reports symptoms B + C persist after rebuild. The plain-Tray branch helps but still routes link items through compileMenu, which gates on focusedWindow — explaining symptom C and possibly symptom B depending on macOS event ordering."
  timestamp: 2026-05-23T12:00:00Z

- hypothesis: "Patching menuBar.js to call setTemplateImage(true) solves symptom A."
  evidence: "Applied in prior UAT-3 cycle. The patch is correct in principle but the source asset is a fully-opaque dark PNG — applying template flag to a fully-opaque image paints the whole frame solid in dark mode. Asset regeneration is also required."
  timestamp: 2026-05-23T12:00:00Z

## Evidence

- timestamp: 2026-05-23T12:00:00Z
  checked: resources/brand/tray-icon.png contents
  found: 44x44 8-bit RGBA, alpha is non-zero across the entire frame (the image is essentially a tiny dark thumbnail of the app icon, NOT a transparent-background silhouette).
  implication: When `setTemplateImage(true)` is applied macOS uses alpha as silhouette → solid white block in dark mode. Symptom A root cause confirmed.

- timestamp: 2026-05-23T12:00:00Z
  checked: nativephp/electron/electron-plugin/dist/server/api/helper/index.js (compileMenu)
  found: Link-type menu items have a click handler `(menuItem, focusedWindow, combo) => { ... if (!focusedWindow) return; goToUrl(item.url, id); }`. The early return when no window is focused is the cause of symptom C.
  implication: The tray menu items inherited through the MenuBar path are window-coupled and cannot work after the main window is closed. A different architecture is required.

- timestamp: 2026-05-23T12:00:00Z
  checked: nativephp/electron/electron-plugin/dist/server/api/window.js /open handler
  found: When called with an existing id, it does `state.windows[id].show(); .focus();`. When called with a new id, it creates a fresh BrowserWindow. This is the canonical re-open path.
  implication: A direct Electron Tray click handler should POST to the NativePHP server's /window/open with the same payload `WindowManager::open('main')` produces, to bring the window back from any state.

## Resolution

root_cause: |
  Architectural — the tray is implemented via NativePHP's `MenuBar`
  facade (a wrapper around the npm `menubar` library), so the tray
  context-menu's "link" items inherit a click handler that early-returns
  when no window is focused (helper/index.js `compileMenu`). Combined
  with macOS lifecycle behavior of the menubar library, this produces
  three coupled symptoms:
    A. The committed tray-icon asset is a fully-opaque dark PNG, so
       applying setTemplateImage(true) paints the whole frame solid
       white in dark mode.
    B. The MenuBar paradigm fights with the main BrowserWindow for
       lifecycle ownership — even in onlyShowContextMenu(true) mode,
       the tray's visibility appears coupled to the menubar machinery.
    C. The tray's right-click items cannot open a closed window because
       their click handlers gate on `focusedWindow`.

  The proper fix is to drop NativePHP's MenuBar API entirely for the
  tray and create a native Electron `Tray` directly in the main process
  via a durable prebuild patch. The tray's lifecycle is then owned by
  the Electron main process (lives for `app.lifetime`), and its menu
  items POST directly to NativePHP's `/window/open` HTTP API which
  shows+focuses the existing window OR creates a fresh one.

fix: |
  Architectural rewrite. Removed the NativePHP `MenuBar` facade from the
  tray path entirely; created the persistent macOS menu-bar tray
  directly in the Electron main process via a new durable prebuild
  patch (`scripts/nativephp_inject_persistent_tray.php`). The patch:

    - Broadens `nativephp/electron/src/main/index.js`'s `import { app }
      from 'electron'` to also pull `Menu`, `Tray`, and `nativeImage`.
    - Inserts a module-scoped `Tray` setup block right after
      `NativePHP.bootstrap(...)`. The block waits for NativePHP's own
      bootstrap to publish `state.electronApiPort` and `state.randomSecret`,
      then constructs `new Tray(nativeImage)` with `setTemplateImage(true)`
      flagged so macOS auto-tints it for the active menu-bar appearance.
    - Builds the verbatim three-row context menu (Open diederik / Scan
      email now / Quit). Each menu item's click handler invokes
      `bringMainWindowToFront`, which:
        * if `state.windows.main` still exists → `restore()` + `show()` +
          `focus()` it (and for "Scan email now", navigate to /inboxes).
        * otherwise (X-button closed → entry deleted from state) → POST
          to `http://127.0.0.1:${state.electronApiPort}/api/window/open`
          with the same payload `WindowManager::open('main')` would have
          produced, which constructs a fresh BrowserWindow with the
          persisted geometry.
        * "Quit" calls `app.quit()` directly — no HTTP roundtrip.

  Asset regeneration: replaced the fully-opaque `resources/brand/tray-icon.png`
  (which produced the "big white block" under template tinting because
  alpha covered the entire frame) with a proper black-on-transparent
  monochrome silhouette derived from the woman-with-crown app icon. Added
  a `tray-icon@2x.png` sibling at 44x44 so macOS picks the high-res
  asset on Retina displays. The generator is committed as a one-shot
  helper (`scripts/regenerate_tray_icon.php`) using GD's resample +
  threshold pipeline; the produced PNGs are committed to the repo and
  staged into the build by an updated `nativephp_stage_build_resources.php`.

  Cleanup:
    - Deleted the obsolete `nativephp_patch_tray_template_image.php`
      script and its test — that patch targeted NativePHP's
      `/api/menu-bar/create` handler which is no longer reached.
    - Deleted `Modules/Desktop/Internal/Native/TrayMenuBuilder.php`
      and its `TrayMenuBuilderTest` — the labels are now the source of
      truth in the JS injection.
    - Removed `MenuBar` from the BoundaryArchTest and `phpstan.neon`
      facade carve-outs; `TrayMenuBuilder` removed from BoundaryArchTest;
      the carve-out regex tightened from
      `(Menu|MenuBar|Window|System|Notification|App)` to
      `(Menu|Window|System|Notification|App)`.
    - Removed `TrayMenuBuilder` binding from `DesktopServiceProvider`.
    - Trimmed `MenuBar` from `Modules/Desktop/Internal/Native/NATIVEPHP-FAKES.md`.
    - `NativeAppServiceProviderTest`: replaced the MenuBar-payload
      assertion with an `assertNotSent` guard against accidentally
      reintroducing a `MenuBar::create()` call.

  New tests:
    - `Modules/Desktop/tests/Unit/InjectPersistentTrayScriptTest.php`
      (11 cases): broadens-imports, single-marker, template-flag,
      verbatim-three-rows, secret-header POST, dimension parity with
      the PHP provider, idempotency, two failure modes, paren-balancing,
      and splice-after-bootstrap ordering.
verification: |
  - `./vendor/bin/pest Modules/Desktop` — 107 passed, 7 todos, 0 failed.
  - `./vendor/bin/pest Modules/Desktop/tests/Unit/InjectPersistentTrayScriptTest.php`
    — 11 passed, 25 assertions.
  - `./vendor/bin/pest` (full suite) — 2190 passed, 6 skipped, 7 todos,
    0 failed.
  - `./vendor/bin/pest --filter='Arch'` — 50 passed.
  - `./vendor/bin/pint --test` — passed.
  - `composer analyse -- --memory-limit=1G` (Larastan level 10 strict)
    — No errors.
  - The patched `nativephp/electron/src/main/index.js` parses cleanly:
    `node --check` exits 0.
  - The inject script is idempotent: a second invocation reports
    "already wired — skipping" and leaves the file unchanged.
  - The new tray-icon.png is a 22x22 RGBA PNG with alpha=0 outside the
    silhouette and RGB=black, alpha=255 inside — verified via the
    rendered preview (recognizable crown+head pictogram, no big block).

  Visual confirmation requires the user to rebuild + relaunch the
  packaged .app on macOS — see the re-verification steps in the
  agent report.
files_changed:
  - scripts/regenerate_tray_icon.php (new — one-shot GD-based silhouette generator)
  - scripts/nativephp_inject_persistent_tray.php (new — prebuild patch)
  - scripts/nativephp_stage_build_resources.php (added tray-icon staging)
  - scripts/nativephp_patch_tray_template_image.php (removed — dead code)
  - resources/brand/tray-icon.png (regenerated, 22x22 silhouette)
  - resources/brand/tray-icon@2x.png (new, 44x44 sibling)
  - Modules/Desktop/Internal/NativeAppServiceProvider.php (dropped MenuBar)
  - Modules/Desktop/Internal/Native/TrayMenuBuilder.php (removed)
  - Modules/Desktop/Internal/Native/NATIVEPHP-FAKES.md (updated table)
  - Modules/Desktop/Providers/DesktopServiceProvider.php (dropped binding)
  - Modules/Desktop/tests/Unit/NativeAppServiceProviderTest.php (rewrote MenuBar test as assertNotSent)
  - Modules/Desktop/tests/Unit/TrayMenuBuilderTest.php (removed)
  - Modules/Desktop/tests/Unit/PatchTrayTemplateImageScriptTest.php (removed)
  - Modules/Desktop/tests/Unit/InjectPersistentTrayScriptTest.php (new)
  - config/nativephp.php (swapped patch_tray_template_image for inject_persistent_tray)
  - tests/Contracts/BoundaryArchTest.php (carve-out updated)
  - phpstan.neon (carve-out regex tightened)
