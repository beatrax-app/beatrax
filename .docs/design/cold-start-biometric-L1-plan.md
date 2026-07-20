# L1 plan — cold-start biometric items 1, 3, 4

Plan step of the plan→fix→review loop. Decisions locked: **Tier A (enclave)**,
**PIN floor**, **first-party plugin**. Excludes item 2 (on-device verification).
Item numbers match `.docs/design/cold-start-biometric-unlock.md` §Remaining.

## Item 3 — Enrollment UX + PIN floor (build first; unblocks the feature)

The R2 reviewers' top finding: `enroll()` has no caller, so the vault is never
populated and `recover()` always returns MISSING. This item makes cold-start
unlock actually reachable.

- **Enrollment action** (Auth Public, `EnrollColdStartBiometric`): given the
  live data key (session unlocked) + a fresh PIN verification, call
  `BiometricKeyVault::enroll($dataKey)` and record an enrollment marker.
  Precondition: session unlocked AND a fresh PIN entered *now* (re-verify via
  `PinVerificationService`) — the PIN is the enrollment root even though
  biometric becomes the unlock root.
- **Enrollment state** without a biometric prompt: a boolean column on
  `user_app_lock_configs` (`cold_start_biometric_enrolled`) — migration. Lets
  the lock screen show the biometric option and settings show the toggle
  without triggering Face ID just to check state. (Avoids the second-plugin
  marker coupling the reviewers flagged.)
- **Settings toggle**: a mobile settings surface (mirror `AppLockSettingsSection`)
  — enable (prompts PIN → enroll), disable (→ item 4 `clear()`).
- **PIN floor** (config `cold_start_pin_floor_days`, default 14): the lock
  screen offers biometric only when enrolled AND the floor is not due. Floor is
  due when: first launch after install (no `last_pin_unlock_at`), OS biometry
  changed (recover returned FAILED-invalidated / a re-enroll signal), or
  `now - last_pin_unlock_at > floor_days`. When due, hide biometric, force PIN;
  a successful PIN refreshes `last_pin_unlock_at`.
- **Tests**: enroll requires unlocked + fresh PIN; enrolled flag drives the
  toggle/lock-screen; floor-due hides biometric; PIN unlock refreshes the floor.

## Item 4 — Lifecycle hooks (build alongside 3; safety-critical)

`clear()` has no caller — a stale blob wrapping an old/ revoked key could
survive. Wire every invalidation path:

- **Disable**: settings-off → `clear()` + reset the enrolled flag.
- **PIN change** (`AppLockPassphraseChanged` event already exists): the data key
  is unchanged by a PIN change, so the blob stays valid — but re-affirm by
  listening and, if enrolled, leaving the blob (documented no-op) OR rewrap to
  be safe. Decision: leave valid (DK unchanged); add a test pinning that.
- **Phase 14 rekey / epoch change**: the DK changes → the stored blob no longer
  unwraps to a usable key. Listen for the rekey/epoch event (Sync module Public
  event) → `clear()` + reset the enrolled flag → force one PIN re-enroll. This
  is the "invalidate-and-re-enroll" from the design lifecycle table.
- **Device revocation** (Phase 14): on a local revocation signal → `clear()`.
- **Tests**: disable clears; rekey event clears + resets flag; a cleared vault
  → `recover()` MISSING.

## Item 1 — Android async recover (native + event; on-device-gated)

- **`set()`-async contract** (R2 finding A3): `BiometricVault::set()` returns a
  status able to express `async` (not a bare bool); `enroll()` returns an
  enum (`enrolled` / `pending_async` / `failed`). On iOS enroll is synchronous;
  on Android it dispatches a prompt and completes via event.
- **Event listeners** (the reviewers' B2): a Livewire listener on
  `MobileLockScreen` (and the enrollment surface) for `BiometricVault.Recovered`
  { key } → `unlockWithRecoveredKey`; `BiometricVault.Failed` → surface + PIN.
  Mirror how `mobile-biometrics` `Completed` events are consumed.
- **Native completion** (Kotlin, from the spike skeleton): wire the host
  `FragmentActivity` into `promptAndRun`, persist `cipher.iv` after
  `init`, emit `BiometricVault.Recovered/Failed` via the plugin event API.
  **On-device iteration required** — cannot be compiled/verified in-repo; this
  is the one L1 piece that lands as reviewed-but-unverified native code.
- **Tests**: the event handlers (fake event → admit / no-admit) at the
  component seam, like the R2 cold-start tests; native is on-device UAT.

## Sequencing

1. Item 3 core (enroll action + migration + enrolled flag) + item 4 clear-on-
   disable + rekey-clear — all testable in-repo. Build together.
2. PIN floor logic + tests.
3. Item 1: `set()`-async contract + event-listener seam + tests; Kotlin
   completion marked on-device.
4. Deep review (3-agent) → fix all severities → gates green.

## Build status (fix step)

**Built & green in-repo:**
- Item 3 core: migration (`cold_start_biometric_enrolled` + `last_pin_unlock_at`
  on `user_app_lock_configs`); `MobileLockGateway::markColdStartEnrolled/
  isColdStartEnrolled/pinFloorDue`; `ColdStartEnrollmentService` (PIN-rooted
  enroll + disable); `PinVerificationService` stamps `last_pin_unlock_at`;
  `MobileLockScreen::mount()` offers biometric only when enrolled + vault
  available + floor not due. 13 tests (enroll ok / wrong-PIN / unavailable /
  disable / floor fresh-due / floor fresh-ok / floor stale-due / mount ready /
  mount floor-due).
- Item 4 partial: the **disable** path (`clear()` + reset flag) is wired + tested.

**All three blockers RESOLVED (built + green):**
- Item 4 **rekey/revocation hook** — the blocker DISSOLVED. Verified against the
  key-wrap chain: the app-lock data key the blob wraps is STABLE; a GDK epoch
  rotation re-wraps under it (doesn't change it), so clearing on a GDK rotation
  would be wrong. The correct, future-proof hook is the EXISTING Auth
  `AppLockPassphraseChanged` event — `ClearColdStartVaultOnKeyRotation` clears
  only when `oldKek !== newKek` (an actual DK rotation). No Sync change.
- Item 1 **Android async** — `completePendingRecover()` +
  `onColdStartRecovered`/`onColdStartFailed` `#[On]` handlers (the recovered key
  never crosses the JS bridge — the native prompt stashes it, PHP re-fetches via
  `BiometricVault.PollRecovered`); plugin `pollRecovered()` + manifest entry.
  Kotlin `BiometricPrompt` completion + the native transient slot stay on-device.
- **Settings toggle UI** — `ColdStartBiometricSettingsSection` (Livewire + view):
  PIN-gated enable, disable, and an empty-state on non-biometric builds.

**Review step:** run the 3-agent deep review of the full L1 surface next.

## Out of scope (unchanged)

Item 2 on-device verification; the pre-existing BoundaryArchTest debt.
