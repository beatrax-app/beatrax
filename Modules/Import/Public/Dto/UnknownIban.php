<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

final class UnknownIban extends Data
{
    public function __construct(
        public readonly string $iban,
        public readonly ?string $seenCounterpartyName,
    ) {}
}
