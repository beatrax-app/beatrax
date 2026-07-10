<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The NativePHP-mobile-booted application provider — an INVERTED skeleton
 * of `Modules\Desktop\Internal\NativeAppServiceProvider`.
 *
 * The exact config key NativePHP mobile resolves this class from
 * (`config('nativephp-mobile.provider')` or an equivalent) is UNCONFIRMED
 * (RESEARCH.md Open Question #3 / PATTERNS.md "No Analog Found" #3) and
 * left to the Wave-0 spike (15-02) to resolve — this class is deliberately
 * NOT wired into any NativePHP config yet. It exists now purely so later
 * waves have a single, already-reviewed home for mobile-native boot hooks
 * without needing to touch `MobileServiceProvider`/`Routes/*`.
 *
 * CRITICAL INVERSION FROM THE DESKTOP ANALOG: `boot()` MUST NEVER keep a
 * background listener alive across requests. The desktop analog spawns a
 * long-lived native subprocess that NativePHP auto-restarts on exit to
 * keep the LAN sync listener up — neither iOS nor Android permit an app to
 * run an arbitrary always-on background process or accept inbound
 * connections while backgrounded (RESEARCH.md Pitfall 1, 15-PATTERNS.md
 * "Anti-Patterns to Avoid"). The phone is an OUTBOUND CLIENT ONLY: any
 * future dial-out trigger here must be a BOUNDED burst (open -> sync ->
 * close), never a long-running process. Only the `class_exists()` guard +
 * non-fatal try/catch-log-and-continue SHAPE is copied from the desktop
 * provider — the always-on subprocess call itself is not.
 *
 * `boot()` is presently a documented no-op. A future wave (Plan 04's
 * `MobileFirstLaunchBootstrap` on-launch migrate, or Plan 05's bounded
 * `MobileSyncTriggerService` dial-out) wires its hook inside
 * `triggerBoundedDialOutIfWired()` once that class exists — this file
 * itself never needs another edit; only the guarded body fills in.
 */
final class NativeMobileAppServiceProvider
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function boot(): void
    {
        try {
            $this->triggerBoundedDialOutIfWired();
        } catch (Throwable $e) {
            // Non-fatal: a boot-time mobile hook failing must never prevent
            // the app from opening (mirrors the desktop analog's own
            // sync-listener try/catch discipline).
            $this->logger->warning('NativePHP mobile boot: dial-out hook failed non-fatally.', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Documented no-op today. class_exists()-guarded so a future wave can
     * wire a BOUNDED dial-out burst here (never a background listener that
     * outlives a single request), without this file needing another
     * structural edit.
     */
    private function triggerBoundedDialOutIfWired(): void
    {
        $triggerService = 'Modules\Mobile\Internal\Sync\MobileSyncTriggerService';
        if (! class_exists($triggerService)) {
            return;
        }

        // Future wave (Plan 05): construct + invoke a BOUNDED dial-out
        // burst via MobileSyncTriggerService::syncOnce() here. MUST remain
        // a single open -> sync-burst -> close call — never a background
        // listener kept alive across requests (RESEARCH Pitfall 1).
    }
}
