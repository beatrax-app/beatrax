<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Asn;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Payment-type hinter specialised for ASN CAMT.053 ISO20022 exports
 * (`source_format = 'camt053'`).
 *
 * Keys off the authoritative `BkTxCd` (Bank Transaction Code) tuple
 * the CAMT.053 adapter persists into `rawPayload['sepa']['btc']` —
 * `domain` (e.g. `PMNT`), `family` (e.g. `CCRD`), `subFamily`
 * (e.g. `POSD`). These three codes carry the official ISO20022
 * meaning of the entry and resolve `PaymentType` without any
 * description-keyword guesswork:
 *
 *  - `PMNT-CCRD-POSD` (Card payment, POS terminal) → Pin
 *  - `PMNT-IDDT-ESDD` / `PMNT-IDDT-PMDD` (Direct debit) → DirectDebit
 *  - `PMNT-ICDT-ESCT` (SEPA credit transfer initiated) → Transfer
 *  - `PMNT-RCDT-ESCT` (SEPA credit transfer received) → Transfer
 *
 * When the BkTxCd tuple is missing OR carries a code outside this
 * recognised list, the hinter falls back to the same Dutch lexeme
 * scan as the ASN CSV / MT940 hinters — CAMT entries carry the same
 * narrative text in their `additionalTransactionInformation` /
 * remittance fields, which the adapter merges into the canonical
 * `description`.
 *
 * Returns `null` when the row did not originate from a CAMT.053
 * import or when neither the structured code lookup nor the
 * description keyword scan finds a match.
 *
 * Pure / stateless / singleton-safe — no constructor dependencies.
 */
final class AsnCamt053PaymentTypeHinter implements PaymentTypeHinter
{
    private const SOURCE_FORMAT = 'camt053';

    /**
     * Structured BkTxCd tuple → (`PaymentType`, confidence) mapping.
     * The tuple key is `domain|family|subFamily`. Matches are
     * exact; partial-tuple matches are intentionally not supported
     * because the ISO20022 spec defines the meaning at the full
     * triple-code level.
     *
     * @var array<string, array{type: PaymentType, confidence: int, sourceHint: string}>
     */
    private const BTC_MAP = [
        'PMNT|CCRD|POSD' => ['type' => PaymentType::Pin, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-CCRD-POSD'],
        'PMNT|CCRD|POSC' => ['type' => PaymentType::Pin, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-CCRD-POSC'],
        'PMNT|IDDT|ESDD' => ['type' => PaymentType::DirectDebit, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-IDDT-ESDD'],
        'PMNT|IDDT|PMDD' => ['type' => PaymentType::DirectDebit, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-IDDT-PMDD'],
        'PMNT|RCDT|ESDD' => ['type' => PaymentType::DirectDebit, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-RCDT-ESDD'],
        'PMNT|ICDT|ESCT' => ['type' => PaymentType::Transfer, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-ICDT-ESCT'],
        'PMNT|RCDT|ESCT' => ['type' => PaymentType::Transfer, 'confidence' => 95, 'sourceHint' => 'btc:PMNT-RCDT-ESCT'],
    ];

    /**
     * Fallback keyword set when BkTxCd is missing or unrecognised.
     * Mirrors the ASN CSV / MT940 hinters because CAMT entries carry
     * the same Dutch narrative text in the merged description.
     *
     * @var list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    private const KEYWORDS = [
        ['keyword' => 'betaalautomaat', 'type' => PaymentType::Pin, 'confidence' => 90],
        ['keyword' => 'geldautomaat', 'type' => PaymentType::Pin, 'confidence' => 90],
        ['keyword' => 'automatische incasso', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'sepa direct debit', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'incasso', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'ideal', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'onlinebetaling', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'online betaling', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'sepa credit transfer', 'type' => PaymentType::Transfer, 'confidence' => 70],
        ['keyword' => 'overboeking', 'type' => PaymentType::Transfer, 'confidence' => 70],
    ];

    public function hint(CanonicalTransaction $tx, string $sourceFormat): ?PaymentTypeHint
    {
        if ($sourceFormat !== self::SOURCE_FORMAT) {
            return null;
        }

        $btcHint = $this->hintFromBtc($tx);
        if ($btcHint !== null) {
            return $btcHint;
        }

        return $this->hintFromDescription($tx);
    }

    private function hintFromBtc(CanonicalTransaction $tx): ?PaymentTypeHint
    {
        $rawPayload = $tx->rawPayload;
        if (! is_array($rawPayload)) {
            return null;
        }

        $sepa = $rawPayload['sepa'] ?? null;
        if (! is_array($sepa)) {
            return null;
        }

        $btc = $sepa['btc'] ?? null;
        if (! is_array($btc)) {
            return null;
        }

        $domain = is_string($btc['domain'] ?? null) ? $btc['domain'] : null;
        $family = is_string($btc['family'] ?? null) ? $btc['family'] : null;
        $subFamily = is_string($btc['subFamily'] ?? null) ? $btc['subFamily'] : null;

        if ($domain === null || $family === null || $subFamily === null) {
            return null;
        }

        $key = $domain.'|'.$family.'|'.$subFamily;
        if (! isset(self::BTC_MAP[$key])) {
            return null;
        }

        $entry = self::BTC_MAP[$key];

        return new PaymentTypeHint(
            type: $entry['type'],
            confidence: $entry['confidence'],
            sourceHint: $entry['sourceHint'],
        );
    }

    private function hintFromDescription(CanonicalTransaction $tx): ?PaymentTypeHint
    {
        if ($tx->description === null || $tx->description === '') {
            return null;
        }

        $haystack = mb_strtolower($tx->description);
        foreach (self::KEYWORDS as $entry) {
            if (mb_strpos($haystack, $entry['keyword']) !== false) {
                return new PaymentTypeHint(
                    type: $entry['type'],
                    confidence: $entry['confidence'],
                    sourceHint: $entry['keyword'],
                );
            }
        }

        return null;
    }
}
