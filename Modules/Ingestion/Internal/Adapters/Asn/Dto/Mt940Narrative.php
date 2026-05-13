<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn\Dto;

/**
 * Decoded `:86:` narrative tag — either structured (with a 3-digit GVC
 * code followed by `?NN`-prefixed subfields) or unstructured (free text
 * stored only in `$rawText`, all other fields null).
 *
 * `gvcKeywords` is a flat map keyed by SEPA GVC keyword (EREF, MREF,
 * CRED, SVWZ, KREF, PURP, IBAN, BIC, ABWA, MDAT, COAM, OAMT) extracted
 * from the purpose subfields. Each value is the raw text following the
 * keyword up to the next `+` separator or the next `?NN` boundary.
 *
 * Lives under `Internal/` because the shape is parser-implementation
 * detail.
 */
final readonly class Mt940Narrative
{
    /**
     * @param  array<string, ?string>  $gvcKeywords
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
