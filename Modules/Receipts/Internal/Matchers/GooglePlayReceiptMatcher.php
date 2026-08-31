<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Matchers;

use Modules\Core\Public\Support\SafeDate;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\ParsedMimeMessage;

// Claims messages whose sender is EXACTLY googleplay-noreply@google.com
// — exact equality, not a google.com suffix match, defeats a spoofed
// look-alike sender. Amounts are NEGATED (receipts confirm outgoing
// charges); a "refund" subject skips rather than resolves the pairing.
final readonly class GooglePlayReceiptMatcher implements SenderMatcher
{
    private const string MATCHER_KEY = 'google-play-receipt';

    private const string GOOGLE_PLAY_SENDER = 'googleplay-noreply@google.com';

    private const string ORDER_ID_REGEX = '/GPA\.[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{5}/';

    private const string USD_AMOUNT_REGEX = '/\$\s*([0-9.,]+)\s*USD/i';

    private const string ITEM_REGEX = '/Item(?:\s*Name)?:\s*(.+)/i';

    private const string SUBSCRIPTION_REGEX = '/Your subscription with\s+(.+)/i';

    private const string REFUND_SUBJECT_REGEX = '/refund/i';

    public function __construct(
        private EmlMimeReader $reader,
        private ReceiptBodyText $text,
    ) {}

    // Parenthesised, Dutch comma decimal — matches both `(€12,07 EUR)` and
    // `(€ 12,07 EUR)`. The settled leg is whatever code the parentheses carry:
    // a store billing in yen writes `(¥1,250 JPY)`, and the euro this pattern
    // used to spell twice left that leg unread and the charge settled in USD.
    private static function settledRegex(): string
    {
        return '/\((?:'.ReceiptBodyText::currencyMarkers().')?\s*([0-9]+(?:[.,][0-9]+)*)\s*([A-Z]{3})\)/i';
    }

    public function key(): string
    {
        return self::MATCHER_KEY;
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
            $body = $this->text->plainText($parsed->htmlBody ?? '');
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

        return $this->parseReceipt($parsed, $body, $subject);
    }

    private function parseReceipt(ParsedMimeMessage $parsed, string $body, string $subject): MatchOutcomeDto
    {
        $charge = $this->extractCharge($body);
        if ($charge === null) {
            return MatchOutcomeDto::unmatched();
        }
        [$orderId, $nativeMinor, $settledMinor, $settledCurrency, $merchant] = $charge;

        $bookedAt = SafeDate::normalisedDayOrNull($parsed->headers['date'] ?? '');
        if ($bookedAt === null) {
            return MatchOutcomeDto::unmatched('invalid_date_header');
        }

        $dto = new ParsedReceiptDto(
            merchantName: $merchant,
            amountMinor: $nativeMinor,
            currency: Currency::Usd->value,
            settledAmountMinor: $settledMinor,
            settledCurrency: $settledCurrency,
            referenceId: $orderId,
            bookedAt: $bookedAt,
            ownIban: SyntheticIban::GooglePlay->value,
            description: $merchant,
            rawPayload: [
                'order_id' => $orderId,
                'subject' => $subject,
                'sender' => $parsed->headers['from'] ?? '',
                'body_excerpt' => substr($body, 0, 200),
            ],
        );

        return MatchOutcomeDto::parsed($dto);
    }

    // Native USD charge is mandatory; the parenthesised EUR leg is the
    // optional settled amount. A missing order id, missing USD anchor,
    // or an unparseable USD figure all mean "not a Google Play receipt".
    /**
     * @return array{string, int, int, string, string}|null
     */
    private function extractCharge(string $body): ?array
    {
        if (
            preg_match(self::ORDER_ID_REGEX, $body, $orderMatches) !== 1
            || preg_match(self::USD_AMOUNT_REGEX, $body, $usdMatches) !== 1
        ) {
            return null;
        }
        $nativeMinor = $this->text->amountMinor($usdMatches[1], Currency::Usd->value);
        if ($nativeMinor === null) {
            return null;
        }
        $nativeMinor = -$nativeMinor;

        $settledMinor = $nativeMinor;
        $settledCurrency = Currency::Usd->value;
        if (preg_match(self::settledRegex(), $body, $settledMatches) === 1) {
            $marked = $this->text->currencyMarked($settledMatches[2], Currency::Usd->value);
            $settledValue = $this->text->amountMinor($settledMatches[1], $marked);
            if ($settledValue !== null) {
                $settledMinor = -$settledValue;
                $settledCurrency = $marked;
            }
        }

        return [$orderMatches[0], $nativeMinor, $settledMinor, $settledCurrency, $this->extractMerchant($body)];
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
}
