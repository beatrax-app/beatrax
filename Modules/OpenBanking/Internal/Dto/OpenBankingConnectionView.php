<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Dto;

use Carbon\CarbonImmutable;
use Modules\OpenBanking\Internal\Enums\ConsentStatus;

final readonly class OpenBankingConnectionView
{
    public function __construct(
        public int $connectionId,
        public bool $enabled,
        public string $institutionId,
        public string $bankDisplayName,
        public ConsentStatus $consentStatus,
        public ?CarbonImmutable $consentExpiresAt,
        public ?CarbonImmutable $lastSuccessfulSyncAt,
        public ?CarbonImmutable $lastAttemptAt,
        public ?string $lastAttemptStatus,
        public string $aggregator,
        public string $whatsFetched,
    ) {}
}
