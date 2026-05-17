<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The shape every matcher emits on a successful parse.
 *
 * Native vs settled pair mirrors the SourceTransactionDto contract:
 * EUR-native receipts (most PayPal NL, most ICS) leave
 * `settledAmountMinor`/`settledCurrency` null and NormalizeStage
 * mirrors the native pair into the canonical settled fields.
 * Foreign-currency rows (Google Play USD, some ICS merchants) carry
 * both so the FX information is preserved through to the canonical
 * transaction.
 *
 * `ownIban` is the synthetic IBAN-shaped identifier the receipt
 * pipeline assigns per provider: `'PAYPAL'`, `'ICS-CARD'`,
 * `'GOOGLE-PLAY'`. AccountResolver already user-scopes lookups, so
 * the synthetic value is instance-wide.
 *
 * `rawPayload` archives the matcher's intermediate extraction (e.g.
 * the decoded text body, anchor regex hits) so a future re-parse can
 * audit how the canonical fields were derived without re-opening the
 * `.eml`.
 *
 * `chainHints` carries optional structured cross-source clues
 * (`FundedByCardPayload`, `RefundOfPayload`, …) that the receipt
 * adapter emits as `ChainHintDetected` events after the canonical
 * transaction is recorded. Matchers populate this only when they
 * actually find the relevant anchor in the body; the common case is
 * an empty array.
 *
 * @phpstan-type ChainHintPayload object
 */
final class ParsedReceiptDto extends Data
{
    /**
     * @param  array<int|string, mixed>  $rawPayload
     * @param  list<object>  $chainHints
     */
    public function __construct(
        public readonly string $merchantName,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?int $settledAmountMinor,
        public readonly ?string $settledCurrency,
        public readonly ?string $referenceId,
        public readonly CarbonImmutable $bookedAt,
        public readonly string $ownIban,
        public readonly ?string $description,
        public readonly array $rawPayload,
        public readonly array $chainHints = [],
    ) {}
}
