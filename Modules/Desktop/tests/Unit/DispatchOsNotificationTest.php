<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Desktop\Internal\Listeners\DispatchOsNotification;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\Desktop\Public\Events\NotificationDeepLink;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;

/*
 * Tests the D-12 / D-13 / D-14 context-aware OS notification model.
 *
 * NATIVEPHP-FAKES.md records the `Notification` facade as ABSENT in
 * NativePHP v2 — there is no fakeable client we can intercept the
 * `Notification::title(...)->message(...)->event(...)->reference(...)
 * ->show()` chain against. Where the test needs to assert the FIRED
 * payload (event class + reference URL), the assertion is deferred
 * `->todo()`. Where the test asserts the FOCUS-GATE DECISION (the
 * listener's branch between "fire OS notification" and "do nothing —
 * SystemAlertsBanner handles it"), the assertion is always automated:
 * we `Http::fake()` the NativePHP HTTP client so a fired notification
 * surfaces as an outbound POST to `/api/notification`, and assert the
 * presence / absence of that POST.
 *
 * Focus-gate is always automated; payload-detail assertions are
 * deferred. The four event categories (drift / import / receipts /
 * forecast) all share the same focus-gate seam, so a single
 * focus-suppression test against one event class covers the gate
 * shape for all four.
 */

it('notification suppressed when focused (D-13 — does not fire when the window is focused)', function (): void {
    Http::fake();

    /** @var WindowFocusState $focus */
    $focus = app(WindowFocusState::class);
    $focus->markFocused();

    /** @var DispatchOsNotification $listener */
    $listener = app(DispatchOsNotification::class);

    $listener->handleForecastShortfall(new ForecastShortfallDetected(
        userId: 1,
        accountId: 1,
        scenarioId: null,
        startsAt: Carbon::parse('2026-06-01'),
        endsAt: Carbon::parse('2026-06-15'),
        lowestBalanceMinor: -1500,
        currency: 'EUR',
        bufferUsedMinor: 0,
    ));

    Http::assertNothingSent();
});

it('fires an OS notification when the window is unfocused (D-13 unfocused branch)', function (): void {
    Http::fake();

    /** @var WindowFocusState $focus */
    $focus = app(WindowFocusState::class);
    $focus->markBlurred();

    /** @var DispatchOsNotification $listener */
    $listener = app(DispatchOsNotification::class);

    $listener->handleForecastShortfall(new ForecastShortfallDetected(
        userId: 1,
        accountId: 1,
        scenarioId: null,
        startsAt: Carbon::parse('2026-06-01'),
        endsAt: Carbon::parse('2026-06-15'),
        lowestBalanceMinor: -1500,
        currency: 'EUR',
        bufferUsedMinor: 0,
    ));

    // The Notification facade has no v2 fake — the focus-gate
    // decision surfaces as an outbound POST to `notification` on
    // the NativePHP HTTP client. Asserting the POST happened proves
    // the listener took the "fire" branch.
    Http::assertSent(fn ($request) => str_ends_with((string) $request->url(), '/notification'));
});

it('suppresses the drift-alert OS notification when focused', function (): void {
    Http::fake();
    app(WindowFocusState::class)->markFocused();

    app(DispatchOsNotification::class)->handleDriftAlert(new DriftAlertOpened(
        userId: 1,
        driftAlertId: 42,
        recurringSeriesId: 17,
        direction: 'up',
        deltaMinor: 250,
        annualizedImpactMinor: 3000,
        currency: 'EUR',
    ));

    Http::assertNothingSent();
});

it('fires a drift-alert OS notification when unfocused', function (): void {
    Http::fake();
    app(WindowFocusState::class)->markBlurred();

    app(DispatchOsNotification::class)->handleDriftAlert(new DriftAlertOpened(
        userId: 1,
        driftAlertId: 42,
        recurringSeriesId: 17,
        direction: 'up',
        deltaMinor: 250,
        annualizedImpactMinor: 3000,
        currency: 'EUR',
    ));

    Http::assertSent(fn ($request) => str_ends_with((string) $request->url(), '/notification'));
});

it('uses the UI-SPEC verbatim title "Cash-flow shortfall ahead" for the forecast notification — payload-detail deferred to manual UAT (no v2 fake for Notification)')->todo();

it('uses the UI-SPEC verbatim title "A recurring charge changed" for the drift-alert notification — payload-detail deferred to manual UAT (no v2 fake for Notification)')->todo();

it('uses the UI-SPEC verbatim title "Import finished" for the import-finished notification — payload-detail deferred to manual UAT (no v2 fake for Notification)')->todo();

it('uses the UI-SPEC verbatim title "New receipts found" for the receipts notification — payload-detail deferred to manual UAT (no v2 fake for Notification)')->todo();

it('attaches a NotificationDeepLink click event with a screen-route reference — payload-detail deferred to manual UAT (no v2 fake for Notification)')->todo();

it('exposes NotificationDeepLink as a final readonly class with a string screenRoute', function (): void {
    $reflection = new ReflectionClass(NotificationDeepLink::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();

    $event = new NotificationDeepLink(screenRoute: '/drift-alerts');
    expect($event->screenRoute)->toBe('/drift-alerts');
});
