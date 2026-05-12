<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

/**
 * AccountResolution variant returned when the IBAN already maps to a known
 * Account row. The Import pipeline uses $accountId for direct foreign-key
 * assignment without going through the resolver again.
 */
final class KnownAccount extends AccountResolution
{
    public function __construct(public readonly int $accountId) {}
}
