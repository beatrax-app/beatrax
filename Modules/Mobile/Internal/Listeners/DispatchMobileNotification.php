<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Listeners;

use Modules\Core\Public\Contracts\Clock;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use NativePHP\LocalNotifications\Facades\LocalNotifications;

/**
 * The mobile delivery adapter (D-31/D-32) — the D-32 mirror of
 * `Modules\Desktop\Internal\Listeners\DispatchOsNotification`. This is the
 * SECOND (and last) subscriber `Modules\Notifications` expects on
 * `NotificationDeliverable`: the Notifications module decides WHAT to
 * notify (title/body/deep-link, D-29 copy authority) and persists the row
 * FIRST; this listener only decides whether/how to deliver it to the phone's
 * OS notification centre.
 *
 * Delivery decision order:
 *
 *   1. `SuppressionEvaluator::shouldDeliver()` (D-38 invariant 4) — the SAME
 *      single suppression site `DispatchOsNotification` calls. The row is
 *      ALREADY persisted at this point (by `NotificationWriter`, before this
 *      event was even dispatched) — a suppressed decision means "stay quiet
 *      on this device", never "drop the row" (D-08 / Req 9 shared contract).
 *      Two independently-written suppression checks would drift; this class
 *      deliberately re-implements NONE of quiet-hours / toggle / seeding
 *      logic.
 *   2. NO focus-gate (unlike Desktop): a phone has no window-focus concept —
 *      the OS notification is the only surface a backgrounded/foregrounded
 *      mobile session has, so every non-suppressed deliverable fires.
 *   3. D-24 hide-details: when the device's `hide_details` preference is on,
 *      the OS body is replaced with a detail-free fallback instead of the
 *      event's real body — identical fallback string to Desktop's.
 *
 * Native-chrome carve-out: `fire()` calls the `nativephp/mobile-local-
 * notifications` plugin's `LocalNotifications` facade — the only reachable
 * surface for that plugin's API. The plugin lives ONLY in
 * `mobile-app/vendor` (never the repo-root toolchain this file's own Pest/
 * PHPStan run targets), so every call is `class_exists()`-guarded exactly
 * like every other native crossing in `Modules\Mobile` (mirrors
 * `BiometricUnlockBridge`/`NetworkPolicyResolver`/`SecureStorageKeyCustodian`'s
 * own guard idiom) and the file sits on `phpstan.neon`'s Mobile facade
 * allow-list.
 *
 * `fire()` calls `LocalNotifications::showRaw(array $payload)` — the SAME
 * raw-array entry point the plugin's own `MobileChannel` (its Laravel
 * Notification-channel integration) uses internally, deliberately NOT the
 * fluent `send($id)->title()->body()->url()` builder: the builder's return
 * type is unresolvable under this toolchain (the plugin lives only in
 * `mobile-app/vendor`), so PHPStan cannot see past `mixed` and chaining
 * further method calls on it fails `method.nonObject` — the SAME reason
 * `SecureStorageKeyCustodian`/`BiometricUnlockBridge` narrow a facade's
 * `mixed` result immediately rather than chaining off it. `showRaw()` is a
 * single static call whose `mixed` return is discarded, never chained.
 * `id` is the event's own `notificationId` (the SAME deterministic sha256
 * key `NotificationWriter` derived), so a duplicate delivery attempt for the
 * same row overwrites rather than double-fires. `url` is the plugin's
 * equivalent of Desktop's `->event()->reference()` deep-link mechanism (it
 * round-trips through the plugin's own `NotificationTapped` event when the
 * user taps the banner); omitted entirely when the event carries no deep
 * link rather than inventing a bridge — mobile deep-linking is not a Req 15
 * acceptance criterion.
 *
 * NOTE: intentionally NOT `final`, and `fire()` is `protected` rather than
 * `private` — mirrors `BiometricUnlockBridge`'s own documented precedent.
 * `nativephp/mobile-local-notifications` v0.0.2 ships no fake for its
 * facade (there is no client to intercept), so
 * `DispatchMobileNotificationTest` substitutes a subclass that records
 * `fire()`'s arguments instead of asserting an outbound HTTP call — the real
 * native call path is exercised only by Task 3's on-device proof.
 */
class DispatchMobileNotification
{
    /** Detail-free fallback body used when the device's D-24 hide-details preference is on. Identical string to Desktop's. */
    private const HIDDEN_DETAILS_BODY = 'Open beatrax to see the details.';

    public function __construct(
        private readonly SuppressionEvaluator $suppression,
        private readonly Clock $clock,
    ) {}

    /**
     * Subscriber for `NotificationDeliverable` — the ONE event both
     * platform delivery adapters (Desktop's `DispatchOsNotification`, Mobile
     * here) consume (D-31/D-32).
     */
    public function handleNotificationDeliverable(NotificationDeliverable $event): void
    {
        $decision = $this->suppression->shouldDeliver($event->userId, $event->triggerType, $this->clock->now());

        if (! $decision->deliver) {
            return;
        }

        $body = $decision->hideDetails ? self::HIDDEN_DETAILS_BODY : $event->body;

        $this->fire($event->notificationId, $event->title, $body, $event->deepLinkRoute);
    }

    /**
     * Fires the local OS notification via the mobile plugin's facade.
     * `class_exists()`-guarded: under the repo-root toolchain (every CI
     * machine, every non-mobile test run) the plugin class is absent and
     * this is a silent no-op — never a fatal error.
     */
    protected function fire(string $notificationId, string $title, string $body, ?string $deepLinkRoute): void
    {
        if (! class_exists(LocalNotifications::class)) {
            return;
        }

        $payload = array_filter([
            'id' => $notificationId,
            'title' => $title,
            'body' => $body,
            'url' => $deepLinkRoute,
        ], fn (?string $value): bool => $value !== null);

        // showRaw()'s `mixed` return is intentionally discarded — the facade
        // (and its return type) are statically unresolvable under this
        // toolchain, so nothing further is chained off the result.
        LocalNotifications::showRaw($payload);
    }
}
