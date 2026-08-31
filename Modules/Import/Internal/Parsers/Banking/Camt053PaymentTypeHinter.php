<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Banking;

use Modules\Import\Internal\Parsers\DutchNarrativeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

final class Camt053PaymentTypeHinter extends DutchNarrativeHinter
{
    protected const SOURCE_FORMAT = SourceFormat::Camt053->value;

    // Keyed domain|family|subFamily and matched exactly: ISO 20022 defines
    // meaning only at the full triple.
    /**
     * @var array<string, array{type: PaymentType, confidence: int, sourceHint: string}>
     */
    private const array BTC_MAP = [
        'PMNT|CCRD|POSD' => ['type' => PaymentType::Pin, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-CCRD-POSD'],
        'PMNT|CCRD|POSC' => ['type' => PaymentType::Pin, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-CCRD-POSC'],
        'PMNT|IDDT|ESDD' => ['type' => PaymentType::DirectDebit, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-IDDT-ESDD'],
        'PMNT|IDDT|PMDD' => ['type' => PaymentType::DirectDebit, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-IDDT-PMDD'],
        'PMNT|RCDT|ESDD' => ['type' => PaymentType::DirectDebit, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-RCDT-ESDD'],
        'PMNT|ICDT|ESCT' => ['type' => PaymentType::Transfer, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-ICDT-ESCT'],
        'PMNT|RCDT|ESCT' => ['type' => PaymentType::Transfer, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-RCDT-ESCT'],
    ];

    public function hint(CanonicalTransaction $tx, string $sourceFormat): ?PaymentTypeHint
    {
        $btcHint = $this->handles($sourceFormat) ? $this->hintFromBtc($tx) : null;

        return $btcHint ?? parent::hint($tx, $sourceFormat);
    }

    private function hintFromBtc(CanonicalTransaction $tx): ?PaymentTypeHint
    {
        $key = self::btcKey($tx->rawPayload);
        if ($key === null || ! isset(self::BTC_MAP[$key])) {
            return null;
        }

        $entry = self::BTC_MAP[$key];

        return new PaymentTypeHint(
            type: $entry['type'],
            confidence: $entry['confidence'],
            sourceHint: $entry['sourceHint'],
        );
    }

    private static function btcKey(mixed $rawPayload): ?string
    {
        $sepa = is_array($rawPayload) ? ($rawPayload['sepa'] ?? null) : null;
        $btc = is_array($sepa) ? ($sepa['btc'] ?? null) : null;
        if (! is_array($btc)) {
            return null;
        }

        $domain = is_string($btc['domain'] ?? null) ? $btc['domain'] : null;
        $family = is_string($btc['family'] ?? null) ? $btc['family'] : null;
        $subFamily = is_string($btc['subFamily'] ?? null) ? $btc['subFamily'] : null;

        if ($domain === null || $family === null || $subFamily === null) {
            return null;
        }

        return $domain.'|'.$family.'|'.$subFamily;
    }
}
