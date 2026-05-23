<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\Desktop\Public\Events\NotificationDeepLink;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Import\Public\Events\TransactionImported;
use Native\Desktop\Facades\Notification;

/**
 * Context-aware OS notification dispatcher (D-12 / D-13 / D-14).
 *
 * Subscribes to the four in-app event categories the UI-SPEC names —
 * drift alert detected, import finished, new receipts found, forecast
 * shortfall — and decides per-event whether to fire a native OS
 * notification (when the window is unfocused, D-13) or stay quiet
 * (when the window is focused — the in-app `SystemAlertsBanner` is
 * showing the same information).
 *
 * Each fired notification carries the verbatim UI-SPEC title, a
 * substantive body, and a click-event of type `NotificationDeepLink`
 * with the relevant in-app route URL as the reference — NativePHP
 * fires the click event back into the Laravel event bus when the
 * user clicks the notification, so a downstream handler navigates
 * the focused window to the route (D-14).
 *
 * Native-chrome carve-out: this listener calls the
 * `Native\Desktop\Facades\Notification` facade directly. NativePHP's
 * notification API is only reachable through that facade (no
 * container-bound alternative), so the file is added to
 * `BoundaryArchTest`'s facade allow-list + `phpstan.neon` ignore
 * list. The crossing stays confined to this one listener; the
 * `WindowFocusState` collaborator and the `UrlGenerator` come
 * through constructor DI.
 *
 * Per-event noise: the in-app domain events are per-row (e.g.
 * `TransactionImported` fires for each canonical-row INSERT). The
 * notification listener subscribes to those events as-is; per-batch
 * rate limiting is out of scope for this plan and lands later if
 * the unfocused-default fires too often during a large import. The
 * focus-gate (D-13) is the primary noise control: in-app users see
 * the SystemAlertsBanner instead.
 */
final class DispatchOsNotification
{
    /** Verbatim UI-SPEC notification titles (locked copy). */
    public const TITLE_IMPORT_FINISHED = 'Import finished';

    public const TITLE_DRIFT = 'A recurring charge changed';

    public const TITLE_RECEIPTS = 'New receipts found';

    public const TITLE_FORECAST = 'Cash-flow shortfall ahead';

    /** Wire-format keys the `TransactionImported` event carries when the row originated from an email receipt. */
    private const RECEIPT_FORMATS = ['eml', 'mbox'];

    public function __construct(
        private readonly WindowFocusState $focus,
        private readonly UrlGenerator $urls,
    ) {}

    /**
     * Subscriber for `TransactionImported`. The single event carries
     * BOTH the "import finished" case (CSV / CAMT / MT940 / PDF
     * source formats) and the "new receipts found" case (eml /
     * mbox); the body + deep-link route differ. This split keeps
     * the listener idiomatic (one method per UI-SPEC category)
     * without requiring two separate event classes upstream.
     */
    public function handleTransactionImported(TransactionImported $event): void
    {
        if (! $this->shouldFire()) {
            return;
        }

        $source = $event->transaction->source_format ?? '';
        if (in_array($source, self::RECEIPT_FORMATS, true)) {
            $this->fire(
                title: self::TITLE_RECEIPTS,
                body: '1 receipt matched from your email.',
                deepLinkRoute: $this->urls->route('inboxes.index'),
            );

            return;
        }

        $this->fire(
            title: self::TITLE_IMPORT_FINISHED,
            body: '1 transaction added from '.($source !== '' ? $source : 'your import').'.',
            deepLinkRoute: $this->urls->route('imports.new'),
        );
    }

    public function handleDriftAlert(DriftAlertOpened $event): void
    {
        if (! $this->shouldFire()) {
            return;
        }

        $direction = $event->direction === 'up' ? 'up' : 'down';
        $delta = number_format(abs($event->deltaMinor) / 100, 2);
        $currency = $event->currency;

        $this->fire(
            title: self::TITLE_DRIFT,
            body: 'A recurring charge moved '.$direction.' by '.$delta.' '.$currency.'.',
            deepLinkRoute: $this->urls->route('drift.index'),
        );
    }

    public function handleForecastShortfall(ForecastShortfallDetected $event): void
    {
        if (! $this->shouldFire()) {
            return;
        }

        $this->fire(
            title: self::TITLE_FORECAST,
            body: 'Your projected balance dips below zero within the next 30 days.',
            deepLinkRoute: $this->urls->route('forecast.index'),
        );
    }

    /**
     * The D-13 focus-gate: an OS notification fires ONLY when the
     * window is unfocused. When focused, the in-app banner already
     * surfaces the event — no double-notification.
     */
    private function shouldFire(): bool
    {
        return ! $this->focus->isFocused();
    }

    private function fire(string $title, string $body, string $deepLinkRoute): void
    {
        Notification::title($title)
            ->message($body)
            ->event(NotificationDeepLink::class)
            ->reference($deepLinkRoute)
            ->show();
    }
}
