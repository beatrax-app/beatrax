<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Dto;

use Spatie\LaravelData\Data;

final class MigrationAccountDto extends Data
{
    public function __construct(
        public readonly string $sourceExternalId,
        public readonly string $name,
        public readonly string $currency,
        public readonly ?string $kind = null,
    ) {}
}
