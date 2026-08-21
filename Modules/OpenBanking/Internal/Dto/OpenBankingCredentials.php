<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Dto;

use Carbon\CarbonImmutable;

final readonly class OpenBankingCredentials
{
    public function __construct(
        public string $applicationId,
        public string $privateKeyPem,
        public ?string $sessionId,
        public ?CarbonImmutable $consentExpiresAt,
        public ?string $bankScaHost,
        public ?string $institutionId,
    ) {}
}
