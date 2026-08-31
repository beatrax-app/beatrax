<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Listeners;

use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;
use Modules\OpenBanking\Internal\Events\OpenBankingImportedNothing;
use Modules\OpenBanking\Internal\Support\ConnectionAlerts;

/**
 * @link ../../../../.docs/features/open-banking/a-feed-that-imports-nothing.md
 */
final readonly class RaiseOpenBankingNothingImportedAlert
{
    private const string ALERT_KIND = 'open_banking_nothing_imported';

    public function __construct(
        private ConnectionAlerts $alerts,
    ) {}

    public function handle(OpenBankingImportedNothing $event): void
    {
        // The count is the whole difference between this and a quiet week, and
        // it is a plain row total rather than anything the bank said, so it is
        // safe to keep beside the alert for a reader looking at /dev/logs.
        $line = CopyLine::of('core::alerts.messages.open_banking_nothing_imported');

        $this->alerts->raiseOnceForConnection(
            userId: $event->userId,
            connectionId: $event->connectionId,
            kind: self::ALERT_KIND,
            severity: SystemAlertSeverity::Warning->value,
            message: $line->sentence(),
            metadata: StoredCopy::inParams($line) + ['rows_fetched' => $event->rowsFetched],
        );
    }
}
