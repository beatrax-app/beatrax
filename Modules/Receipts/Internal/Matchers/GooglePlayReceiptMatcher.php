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

    private const string USD_FIGURE = '\$\s*([0-9.,]+)\s*USD';

    // The order's total, and only then the line a receipt with no total states
    // instead. Tried in this order because a receipt carries both plus a tax
    // line, and the first denominated figure in the body was whichever of the
    // three the store printed highest.
    /** @var list<string> */
    private const array TOTAL_LABELS = ['Order total|Total', 'Price|Amount'];

    private const string ITEM_REGEX = '/Item(?:\s*Name)?:\s*(.+)/i';

    private const string SUBSCRIPTION_REGEX = '/Your subscription with\s+(.+)/i';

    private const string REFUND_SUBJECT_REGEX = '/refund/i';

    public function __construct(
        private EmlMimeReader $reader,
        private ReceiptBodyText $text,
    ) {}

    // The charge and its conversion come off ONE labelled line, so the settled
    // leg cannot be read from a bracket belonging to another figure: a receipt
    // stating `Price: $11.99 USD (€11,14 EUR)` above `Total: $12.99 USD
    // (€12,07 EUR)` settled the total at the price's euros.
    private static function chargeRegex(string $labels): string
    {
        $markers = ReceiptBodyText::currencyMarkers();

        // Parenthesised, Dutch comma decimal — matches both `(€12,07 EUR)` and
        // `(€ 12,07 EUR)`, closed to the marks this app names on both ends: an
        // item line reading `(30 day)` is the same shape as a denominated
        // figure, and a bare [A-Z]{3} read it as one.
        $settled = '(?:\s*\((?:'.$markers.')?\s*([0-9]+(?:[.,][0-9]+)*)\s*('.$markers.')\))?';

        return '/'.ReceiptBodyText::underLabel($labels, self::USD_FIGURE.$settled).'/i';
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
                'body_excerpt' => mb_strcut($body, 0, 200),
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
        $chargeLine = $this->chargeLine($body);
        if (preg_match(self::ORDER_ID_REGEX, $body, $orderMatches) !== 1 || $chargeLine === null) {
            return null;
        }
        [$nativeRaw, $settledRaw, $settledMark] = $chargeLine;

        $nativeMinor = $this->text->amountMinor($nativeRaw, Currency::Usd->value);
        if ($nativeMinor === null) {
            return null;
        }
        $nativeMinor = -$nativeMinor;

        $settledMinor = $nativeMinor;
        $settledCurrency = Currency::Usd->value;
        if ($settledRaw !== '') {
            $marked = $this->text->currencyMarked($settledMark, Currency::Usd->value);
            $settledValue = $this->text->amountMinor($settledRaw, $marked);
            if ($settledValue !== null) {
                $settledMinor = -$settledValue;
                $settledCurrency = $marked;
            }
        }

        return [$orderMatches[0], $nativeMinor, $settledMinor, $settledCurrency, $this->extractMerchant($body)];
    }

    // The native figure, plus the conversion off that same line where the
    // receipt states one. Empty strings where it does not: the parentheses are
    // an optional group, so preg_match leaves their slots short rather than
    // blank.
    /**
     * @return array{string, string, string}|null
     */
    private function chargeLine(string $body): ?array
    {
        foreach (self::TOTAL_LABELS as $labels) {
            if (preg_match(self::chargeRegex($labels), $body, $matches) === 1) {
                return [$matches[1], $matches[2] ?? '', $matches[3] ?? ''];
            }
        }

        return null;
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
