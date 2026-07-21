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

// Claims messages whose sender is EXACTLY googleplay-noreply@google.com
// — exact equality, not a google.com suffix match, defeats a spoofed
// look-alike sender. Amounts are NEGATED (receipts confirm outgoing
// charges); a "refund" subject skips rather than resolves the pairing.
/**
 * @link ../../../../.docs/features/receipts/architecture.md
 */
final class GooglePlayReceiptMatcher implements SenderMatcher
{
    private const GOOGLE_PLAY_SENDER = 'googleplay-noreply@google.com';

    private const ORDER_ID_REGEX = '/GPA\.[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{5}/';

    private const USD_AMOUNT_REGEX = '/\$\s*([0-9]+(?:\.[0-9]+)?)\s*USD/i';

    // Parenthesised, Dutch comma decimal — matches both `(€12,07 EUR)`
    // and `(€ 12,07 EUR)`.
    private const EUR_SETTLED_REGEX = '/\(€\s*([0-9]+(?:[.,][0-9]+)*)\s*EUR\)/i';

    private const ITEM_REGEX = '/Item(?:\s*Name)?:\s*(.+)/i';

    private const SUBSCRIPTION_REGEX = '/Your subscription with\s+(.+)/i';

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

        // Refund subject — skip with a stable reason. Pairing the
        // refund with the original order id is the Chains module's job.
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

    // Preference order: the Item: line, then the subscription anchor,
    // then the literal "Google Play" fallback (so the canonical row
    // always has a non-empty counterparty).
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

    private function stripHtml(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $stripped = strip_tags($decoded);
        $collapsed = (string) preg_replace('/[ \t]+/', ' ', $stripped);

        return trim($collapsed);
    }

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
