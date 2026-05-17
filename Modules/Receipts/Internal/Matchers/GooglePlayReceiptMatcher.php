<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Matchers;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Throwable;

/**
 * Sender-specific matcher for Google Play order receipts.
 *
 * Claims any message whose sender's email is EXACTLY
 * `googleplay-noreply@google.com`. Plain suffix-match on `google.com`
 * is not enough — Google sends many other notifications from
 * `google.com` (account security, calendar invites, marketing) that
 * are NOT receipts. Exact equality plus a per-fixture spoof negative
 * case keep the matcher tight.
 *
 * Body-extraction policy: Google Play receipts are text/plain primary;
 * the HTML fallback path is never executed in practice (the matcher
 * keeps the same `textBody ?? htmlBody-stripped` shape as the other
 * matchers for consistency).
 *
 * Anchors:
 *
 *  - order id: strict GPA.NNNN-NNNN-NNNN-NNNNN pattern (4-4-4-5
 *    grouped digits). Missing -> MatchOutcomeDto::unmatched().
 *  - native amount: `$ <amount> USD` (US period decimal).
 *  - settled amount (FX optional): `(€ <amount> EUR)` immediately
 *    after the native amount; Dutch comma decimal. When present the
 *    DTO carries BOTH legs verbatim so the FX information survives
 *    through NormalizeStage.
 *  - merchant: extracted from the `Item: <name>` line; the line's
 *    trailing parenthesised quantity / period suffix is preserved as
 *    part of the merchant name so subscription names round-trip
 *    cleanly. Falls back to the literal "Google Play" when neither
 *    the Item line nor an explicit App-name token surfaces.
 *
 * Sign convention: receipts confirm OUTGOING charges, so amounts are
 * NEGATED (`$12.99 USD` -> `-1299` native USD; `€12,07 EUR` ->
 * `-1207` settled EUR). Mirrors the PayPal + ICS matcher policies.
 *
 * Date handling: `CarbonImmutable::parse` inside `try/catch
 * (Throwable)`, normalised to `startOfDay()` so cross-format
 * fingerprint parity holds with any future Google Play CSV twin.
 *
 * Refund handling: subject containing "refund" -> returns
 * `MatchOutcomeDto::skipped('googleplay-refund-v2')`. The matcher
 * cannot resolve the original order id to a transactions row without
 * DB access; pairing refunds with the original order id is a
 * downstream Chains-module concern.
 *
 * Pure / stateless / singleton-safe.
 */
final class GooglePlayReceiptMatcher implements SenderMatcher
{
    /** Exact sender-email equality defeats `google.com.attacker.example` spoofs. */
    private const GOOGLE_PLAY_SENDER = 'googleplay-noreply@google.com';

    /**
     * Strict Google Play order-id pattern: `GPA.NNNN-NNNN-NNNN-NNNNN`
     * (four groups of four digits + final group of five digits).
     */
    private const ORDER_ID_REGEX = '/GPA\.[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{5}/';

    /** Native USD amount anchor — accepts an optional space + the literal `USD` suffix. */
    private const USD_AMOUNT_REGEX = '/\$\s*([0-9]+(?:\.[0-9]+)?)\s*USD/i';

    /**
     * Settled EUR amount anchor — parenthesised, Dutch comma decimal.
     * Matches both `(€12,07 EUR)` and `(€ 12,07 EUR)` shapes.
     */
    private const EUR_SETTLED_REGEX = '/\(€\s*([0-9]+(?:[.,][0-9]+)*)\s*EUR\)/i';

    /** Item line anchor — `Item: <name>` or `Item Name: <name>`. */
    private const ITEM_REGEX = '/Item(?:\s*Name)?:\s*(.+)/i';

    /** Subscription anchor — `Your subscription with <name>` (App-Name fallback). */
    private const SUBSCRIPTION_REGEX = '/Your subscription with\s+(.+)/i';

    /** Refund subject anchor — case-insensitive. */
    private const REFUND_SUBJECT_REGEX = '/refund/i';

    public function __construct(private readonly EmlMimeReader $reader) {}

    public function key(): string
    {
        return 'google-play-receipt';
    }

    public function priority(): int
    {
        return 100;
    }

    public function canHandle(InboxMessageDto $msg): bool
    {
        if ($msg->senderEmail === '') {
            return false;
        }

        return strtolower($msg->senderEmail) === self::GOOGLE_PLAY_SENDER;
    }

    public function match(string $emlRaw): MatchOutcomeDto
    {
        $parsed = $this->reader->read($emlRaw);

        $body = $parsed->textBody;
        if ($body === null || $body === '') {
            $body = $this->stripHtml($parsed->htmlBody ?? '');
        }
        if ($body === '') {
            return MatchOutcomeDto::unmatched();
        }

        // Refund subject -> skip with the deferred-resolver reason.
        // The matcher cannot pair a refund against the original order
        // id without DB access; the Chains module resolver owns that
        // bridge.
        $subject = $parsed->headers['subject'] ?? '';
        if ($subject !== '' && preg_match(self::REFUND_SUBJECT_REGEX, $subject) === 1) {
            return MatchOutcomeDto::skipped('googleplay-refund-v2');
        }

        if (preg_match(self::ORDER_ID_REGEX, $body, $orderMatches) !== 1) {
            return MatchOutcomeDto::unmatched();
        }
        $orderId = $orderMatches[0];

        if (preg_match(self::USD_AMOUNT_REGEX, $body, $usdMatches) !== 1) {
            return MatchOutcomeDto::unmatched();
        }
        $nativeMinor = $this->toMinorOrNull($usdMatches[1], 'USD');
        if ($nativeMinor === null) {
            return MatchOutcomeDto::unmatched();
        }
        $nativeMinor = -$nativeMinor;

        $settledMinor = $nativeMinor;
        $settledCurrency = 'USD';
        if (preg_match(self::EUR_SETTLED_REGEX, $body, $eurMatches) === 1) {
            $eurValue = $this->toMinorOrNull($eurMatches[1], 'EUR');
            if ($eurValue !== null) {
                $settledMinor = -$eurValue;
                $settledCurrency = 'EUR';
            }
        }

        $merchant = $this->extractMerchant($body);

        $dateRaw = $parsed->headers['date'] ?? '';
        try {
            $bookedAt = CarbonImmutable::parse($dateRaw)->startOfDay();
        } catch (Throwable) {
            return MatchOutcomeDto::unmatched('invalid_date_header');
        }

        $dto = new ParsedReceiptDto(
            merchantName: $merchant,
            amountMinor: $nativeMinor,
            currency: 'USD',
            settledAmountMinor: $settledMinor,
            settledCurrency: $settledCurrency,
            referenceId: $orderId,
            bookedAt: $bookedAt,
            ownIban: 'GOOGLE-PLAY',
            description: $merchant,
            rawPayload: [
                'matcher_key' => 'google-play-receipt',
                'order_id' => $orderId,
                'subject' => $subject,
                'sender' => $parsed->headers['from'] ?? '',
                'body_excerpt' => substr($body, 0, 200),
            ],
        );

        return MatchOutcomeDto::parsed($dto);
    }

    /**
     * Pull the merchant string. Preference order:
     *
     *   1. `Item:` line content (subscription name + quantity suffix).
     *   2. `Your subscription with <name>` anchor.
     *   3. The literal `Google Play` as a last-resort fallback so the
     *      canonical row always has a non-empty counterparty (the
     *      NormalizeStage substitutes `_no_counterparty` otherwise).
     */
    private function extractMerchant(string $body): string
    {
        if (preg_match(self::ITEM_REGEX, $body, $itemMatches) === 1) {
            $candidate = trim($itemMatches[1]);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        if (preg_match(self::SUBSCRIPTION_REGEX, $body, $subscriptionMatches) === 1) {
            $candidate = trim($subscriptionMatches[1]);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Google Play';
    }

    /**
     * Strip HTML tags + decode entities so the regex anchors bind
     * against the rendered text rather than the markup. Used only when
     * the message lacks a text/plain part.
     */
    private function stripHtml(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $stripped = strip_tags($decoded);
        $collapsed = (string) preg_replace('/[ \t]+/', ' ', $stripped);

        return trim($collapsed);
    }

    /**
     * Parse a Dutch-comma OR US-period decimal amount into integer
     * minor units via brick/money. Returns null on parse failure.
     */
    private function toMinorOrNull(string $raw, string $currency): ?int
    {
        $normalised = trim($raw);
        if (str_contains($normalised, ',') && str_contains($normalised, '.')) {
            $normalised = str_replace('.', '', $normalised);
            $normalised = str_replace(',', '.', $normalised);
        } elseif (str_contains($normalised, ',')) {
            $normalised = str_replace(',', '.', $normalised);
        }

        try {
            $money = Money::of(BigDecimal::of($normalised), $currency, roundingMode: RoundingMode::HALF_UP);
        } catch (NumberFormatException|UnknownCurrencyException) {
            return null;
        }

        return $money->getMinorAmount()->toInt();
    }
}
