<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

/**
 * AccountResolution variant returned when the IBAN has not yet been linked
 * to an Account row. The upload wizard prompts the user to name the new
 * account and persists the row before re-running the resolver.
 */
final class UnknownAccount extends AccountResolution
{
    public function __construct(public readonly string $iban) {}
}
