<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/community/architecture.md
 */
final class MerchantContactDto extends Data
{
    public function __construct(
        public readonly ?string $website = null,
        public readonly ?string $cancelUrl = null,
        public readonly ?string $supportUrl = null,
        public readonly ?string $supportPhone = null,
        public readonly ?string $supportEmail = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->website === null
            && $this->cancelUrl === null
            && $this->supportUrl === null
            && $this->supportPhone === null
            && $this->supportEmail === null;
    }
}
