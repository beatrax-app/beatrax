<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\EmailScan\Public\Events\IcsStatementReady;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\NotificationCopy;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
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
        // Resolve the deep link FIRST, tolerating a missing route
        // (OpenBanking is optional and may be disabled/unregistered).
        // Evaluating route() inside the write() call would let a
        // RouteNotFoundException abort the persist entirely.
        $deepLinkRoute = $this->resolveDeepLinkRoute();

        try {
            $this->writer->write(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_ICS_STATEMENT_READY,
                subjectKey: 'ics-card',
                occurrence: $event->internalDate->format('Y-m-d'),
                title: NotificationCopy::TITLE_ICS_STATEMENT_READY,
                body: "Download it from the ICS portal and drop it into beatrax to keep this card's spending up to date.",
                params: ['target_kind' => 'ics-import'],
                deepLinkRoute: $deepLinkRoute,
            );
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating detector job run.
            $this->log->error('PersistIcsStatementReady: failed to persist nudge', [
                'exception' => $e->getMessage(),
                'userId' => $event->userId,
            ]);
        }
    }

    // The ICS nudge is conceptually independent of the OpenBanking
    // module, so a missing route (module disabled/route unregistered)
    // must degrade to "no deep link", never stop the row being written.
    private function resolveDeepLinkRoute(): ?string
    {
        try {
            return $this->urls->route('settings.open-banking').'#ics-import';
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
