<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking\Dto;

final readonly class Mt940Narrative
{
    /**
     * @param  array<string, ?string>  $gvcKeywords  keyed by SEPA GVC keyword
     *                                               (EREF, MREF, CRED, SVWZ, KREF, PURP, IBAN, BIC, ABWA, MDAT, COAM,
     *                                               OAMT) extracted from the purpose subfields; each value is the raw
     *                                               text following the keyword up to the next `+` separator or `?NN`
     *                                               boundary
     */
    public function __construct(
        public ?string $gvcCode,
        public array $gvcKeywords,
        public ?string $counterpartyName,
        public ?string $counterpartyIban,
        public ?string $description,
        public string $rawText,
    ) {}
}
