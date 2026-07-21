<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Dto;

// counterpartyId is null only for the self_account branch (routes to the
// account view instead of materialising a row). type is restricted to
// the DB trigger-enforced enum; a personal-type slug never carries the
// iban (privacy default — see the linked doc).
/**
 * @link ../../../../.docs/features/counterparties/architecture.md
 */
final readonly class CounterpartyResolutionDto
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $type,
        public string $displayName,
        public string $slug,
        public ?string $iban,
        public ?string $merchantName,
        public array $metadata,
        public ?int $counterpartyId,
    ) {}
}
