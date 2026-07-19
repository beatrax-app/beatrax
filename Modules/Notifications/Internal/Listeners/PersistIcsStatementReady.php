<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\EmailScan\Public\Events\IcsStatementReady;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\NotificationCopy;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists Req 14's ICS "statement ready" nudge into the unified inbox
 * (D-14/D-15).
 *
 * Subscribes to `Modules\EmailScan\Public\Events\IcsStatementReady` —
 * registered by `NotificationsServiceProvider`'s guarded listener table
 * (the 9th pair, extending the Phase 18 single-owner registration site);
 * this class's exact name/namespace is what flips that guard on.
 *
 * `subjectKey` is the static `'ics-card'` (there is one ICS card per user
 * in this project's model); `occurrence` is the calendar month the
 * statement-ready email arrived (`Y-m`), NOT the message id — a bank-side
 * resend within the same month must collapse to one notification rather
 * than fracture into a second (D-15 "fires once per statement, not per
 * message" — 19-RESEARCH.md Pitfall 4). `params.target_kind = 'ics-import'`
 * mirrors `PersistCoalescedImport`'s no-deletable-entity `'inbox'`/`'import'`
 * shape — `DeepLinkResolver::ALWAYS_LIVE_KINDS` resolves it to the same
 * `settings.open-banking#ics-import` anchor passed as `deepLinkRoute`
 * below, so both the OS-push deep link AND the `/notifications` inbox's
 * render-time link point at the same place.
 *
 * The whole handler body is wrapped in the never-throw envelope (D-07),
 * cloned from `PersistPaymentReminder::handle()`: a failed
 * notification-persist must NEVER break the originating
 * `DetectIcsStatementReadyJob` run.
 */
final class PersistIcsStatementReady
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(IcsStatementReady $event): void
    {
        try {
            $this->writer->write(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_ICS_STATEMENT_READY,
                subjectKey: 'ics-card',
                occurrence: $event->internalDate->format('Y-m'),
                title: NotificationCopy::TITLE_ICS_STATEMENT_READY,
                body: "Download it from the ICS portal and drop it into beatrax to keep this card's spending up to date.",
                params: ['target_kind' => 'ics-import'],
                deepLinkRoute: $this->urls->route('settings.open-banking').'#ics-import',
            );
        } catch (Throwable $e) {
            // Swallow — a failed persist must NEVER break the originating
            // detector job run (D-07).
            $this->log->error('PersistIcsStatementReady: failed to persist nudge', [
                'exception' => $e->getMessage(),
                'userId' => $event->userId,
            ]);
        }
    }
}
