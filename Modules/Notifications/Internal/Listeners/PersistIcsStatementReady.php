<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\EmailScan\Public\Events\IcsStatementReady;
use Modules\Notifications\Internal\Support\CopyLine;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

final class PersistIcsStatementReady
{
    public function __construct(
        private readonly NotificationWriter $writer,
        private readonly UrlGenerator $urls,
        private readonly LoggerInterface $log,
        private readonly NotificationCopyRenderer $copyRenderer,
    ) {}

    public function handle(IcsStatementReady $event): void
    {
        // Resolved first: a route() call inside write() would let a
        // RouteNotFoundException abort the persist entirely.
        $deepLinkRoute = $this->resolveDeepLinkRoute();

        try {
            $copy = NotificationCopySpec::of(
                CopyLine::of('notifications::copy.title.ics_statement_ready'),
                CopyLine::of('notifications::copy.body.ics_statement_ready'),
            );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: DeterministicKeyDeriver::TRIGGER_ICS_STATEMENT_READY,
                subjectKey: 'ics-card',
                occurrence: $event->internalDate->format('Y-m-d'),
                copy: $copy,
                params: ['target_kind' => 'ics-import'],
                deepLinkRoute: $deepLinkRoute,
            ));
            $this->writer->write($draft);
        } catch (Throwable $e) {
            // A failed persist must never break the detector job run.
            $this->log->error('PersistIcsStatementReady: failed to persist nudge', [
                ...SafeExceptionContext::describe($e),
                'userId' => $event->userId,
            ]);
        }
    }

    private function resolveDeepLinkRoute(): ?string
    {
        try {
            return $this->urls->route('settings.open-banking').'#ics-import';
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
