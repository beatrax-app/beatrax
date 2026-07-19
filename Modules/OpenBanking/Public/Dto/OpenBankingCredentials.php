<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Dto;

use Carbon\CarbonImmutable;

/**
 * The credential/session interchange type the secrets repository (19-03)
 * returns and the SSRF-guarded HTTP client (19-04) consumes.
 *
 * `applicationId` and `privateKeyPem` are the caller's own Enable Banking
 * "application" (BYO-key, D-05/06) used to sign the RS256 JWT on every
 * request. `sessionId`/`consentExpiresAt` are populated once the consent
 * dance completes; both are `null` before the user has linked an account.
 * `bankScaHost` is the resolved bank-side SCA host the egress allow-list
 * guard permits alongside the Enable Banking host itself; `institutionId`
 * identifies which bank (ASN vs SNS) this credential set is scoped to.
 *
 * This DTO never round-trips through Eloquent or a DB column (D-07 arch
 * guard) — it is constructed from the chmod-600 secrets file only.
 */
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
