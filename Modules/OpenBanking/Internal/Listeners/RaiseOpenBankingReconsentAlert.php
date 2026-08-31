<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Listeners;

use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;
use Modules\OpenBanking\Internal\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Internal\Support\ConnectionAlerts;

final readonly class RaiseOpenBankingReconsentAlert
{
    private const string ALERT_KIND = 'open_banking_reconsent_required';

    public function __construct(
        private ConnectionAlerts $alerts,
    ) {}

    public function handle(OpenBankingConsentFailed $event): void
    {
        // The banner's own line for this kind, so the row and the screen say
        // the same thing; resolving it here would freeze it in whichever
        // language the sync that failed happened to be running in.
        $line = CopyLine::of('core::alerts.messages.open_banking_reconsent');

        $this->alerts->raiseOnceForConnection(
            userId: $event->userId,
            connectionId: $event->connectionId,
            kind: self::ALERT_KIND,
            severity: SystemAlertSeverity::Warning->value,
            message: $line->sentence(),
            metadata: StoredCopy::inParams($line) + ['reason' => $event->reason],
        );
    }
}
