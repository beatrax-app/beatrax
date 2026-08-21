<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

final class UnknownAccount extends AccountResolution
{
    public function __construct(public readonly string $iban) {}
}
