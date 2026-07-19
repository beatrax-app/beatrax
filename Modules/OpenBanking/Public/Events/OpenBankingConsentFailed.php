<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Events;

/**
 * Event payload dispatched when an Open Banking connection's consent/SCA
 * session fails or expires in a way that requires the user to re-link
 * (re-grant consent) at the aggregator.
 *
 * Mirrors `Modules\EmailScan\Public\Events\InboxTokenFailed` exactly:
 * `connectionId` plays the role `inboxId` plays there. There is no
 * `provider` field — Open Banking has exactly one provider (Enable
 * Banking), so the branching that event supports is not needed here.
 */
final class OpenBankingConsentFailed
{
    public function __construct(
        public readonly int $connectionId,
        public readonly int $userId,
        public readonly string $reason,
    ) {}
}
