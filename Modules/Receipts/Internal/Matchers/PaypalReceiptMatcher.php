<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Matchers;

use Modules\Core\Public\Support\SafeDate;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\ParsedMimeMessage;

// Claims messages whose sender domain is exactly paypal.com (exact
// suffix equality, not str_contains, defeats a spoofed look-alike
// domain). Amounts are NEGATED (receipts confirm outgoing payments); a
// sign-in-notification subject skips rather than treats it as a receipt.
final readonly class PaypalReceiptMatcher implements SenderMatcher
{
    private const string MATCHER_KEY = 'paypal-receipt';

    private const string PAYPAL_DOMAIN = 'paypal.com';

    private const string LOGIN_NOTIFICATION_SUBJECT_REGEX = '/(new (device )?sign-in|new login|inloggen op een nieuw apparaat)/i';

    private const string TRANSACTION_ID_REGEX = '/Transaction ID:\s*([A-Z0-9]{17})/i';

    private const string USD_AMOUNT_REGEX = '/\$\s*([0-9.,]+)\s*USD/i';

    private const string MERCHANT_REGEX = '/(?:Aan|Merchant|To|Paid to):\s*(.+)/i';

    public function __construct(
        private EmlMimeReader $reader,
        private BaseCurrency $baseCurrency,
        private ReceiptBodyText $text,
    ) {}

    // The mark travels into the parse rather than being spelled twice: the
    // symbol class these anchors carried was written before JPY was seeded, so
    // a '¥' figure was read at no currency the message had named.
    private static function markedAmountRegex(): string
    {
        return '/('.ReceiptBodyText::currencyMarkers().')\s*([0-9.,]+)/i';
    }

    private static function labelledAmountRegex(): string
    {
        return '/(?:Bedrag|Amount|Total):\s*('.ReceiptBodyText::currencyMarkers().')?\s*([0-9.,]+)/i';
    }

    // Accepts an optional repeated currency token or symbol before the
    // amount, both of which PayPal has been observed emitting.
    private static function settledConversionRegex(): string
    {
        return '/Conversion to ([A-Z]{3}):?\s*(?:(?:'.ReceiptBodyText::currencyMarkers().')\s*|[A-Z]{3}\s+)?([0-9.,]+)/i';
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

        $atTail = strrchr($msg->senderEmail, '@');
        if ($atTail === false) {
            return false;
        }

        $domain = strtolower(substr($atTail, 1));

        return $domain === self::PAYPAL_DOMAIN;
    }

    public function match(string $emlRaw): MatchOutcomeDto
    {
        $parsed = $this->reader->read($emlRaw);
        $body = $parsed->textBody ?? $parsed->htmlBody;
        if ($body === null || $body === '') {
            return MatchOutcomeDto::unmatched();
        }

        $subject = $parsed->headers['subject'] ?? '';
        if ($subject !== '' && preg_match(self::LOGIN_NOTIFICATION_SUBJECT_REGEX, $subject) === 1) {
            return MatchOutcomeDto::skipped('paypal-login-notification');
        }

        return $this->parseReceipt($parsed, $body, $subject);
    }

    private function parseReceipt(ParsedMimeMessage $parsed, string $body, string $subject): MatchOutcomeDto
    {
        $charge = $this->extractCharge($body);
        $merchant = $this->resolveMerchant($body);
        if ($charge === null || $merchant === null) {
            return MatchOutcomeDto::unmatched();
        }

        return $this->buildOutcome($parsed, $body, $subject, $charge, $merchant);
    }

    /**
     * @param  array{string, int, string, int, string}  $charge
     */
    private function buildOutcome(ParsedMimeMessage $parsed, string $body, string $subject, array $charge, string $merchant): MatchOutcomeDto
    {
        [$transactionId, $nativeAmountMinor, $nativeCurrency, $settledAmountMinor, $settledCurrency] = $charge;

        $bookedAt = SafeDate::normalisedDayOrNull($parsed->headers['date'] ?? '');
        if ($bookedAt === null) {
            return MatchOutcomeDto::unmatched('invalid_date_header');
        }

        $dto = new ParsedReceiptDto(
            merchantName: $merchant,
            amountMinor: $nativeAmountMinor,
            currency: $nativeCurrency,
            settledAmountMinor: $settledAmountMinor,
            settledCurrency: $settledCurrency,
            referenceId: $transactionId,
            bookedAt: $bookedAt,
            ownIban: SyntheticIban::Paypal->value,
            description: $merchant,
            rawPayload: [
                'transaction_id' => $transactionId,
                'subject' => $subject,
                'sender' => $parsed->headers['from'] ?? '',
                'body_excerpt' => substr($body, 0, 200),
            ],
        );

        return MatchOutcomeDto::parsed($dto);
    }

    /**
     * @return array{string, int, string, int, string}|null
     */
    private function extractCharge(string $body): ?array
    {
        if (preg_match(self::TRANSACTION_ID_REGEX, $body, $txMatches) !== 1) {
            return null;
        }
        $amountPair = $this->extractNativeAmount($body);
        if ($amountPair === null) {
            return null;
        }
        [$nativeAmountMinor, $nativeCurrency] = $amountPair;
        [$settledAmountMinor, $settledCurrency] = $this->extractSettledLeg($body, $nativeCurrency, $nativeAmountMinor);

        return [$txMatches[1], $nativeAmountMinor, $nativeCurrency, $settledAmountMinor, $settledCurrency];
    }

    private function resolveMerchant(string $body): ?string
    {
        if (preg_match(self::MERCHANT_REGEX, $body, $merchantMatches) !== 1) {
            return null;
        }
        $merchant = trim($merchantMatches[1]);

        return $merchant === '' ? null : $merchant;
    }

    /**
     * @return array{int, string}|null
     */
    private function extractNativeAmount(string $body): ?array
    {
        // USD is checked first when both `$ X USD` and `EUR Y` appear
        // (foreign-currency receipt shape: native USD, settled EUR) —
        // its anchor is more specific than the bare marked-amount anchor.
        return $this->nativeFromUsd($body)
            ?? $this->nativeFromMarked($body)
            ?? $this->nativeFromLabelled($body);
    }

    /**
     * @return array{int, string}|null
     */
    private function nativeFromUsd(string $body): ?array
    {
        if (preg_match(self::USD_AMOUNT_REGEX, $body, $m) !== 1) {
            return null;
        }
        $minor = $this->text->amountMinor($m[1], Currency::Usd->value);

        return $minor === null ? null : [-$minor, Currency::Usd->value];
    }

    /**
     * @return array{int, string}|null
     */
    private function nativeFromMarked(string $body): ?array
    {
        if (preg_match(self::markedAmountRegex(), $body, $m) !== 1) {
            return null;
        }
        $currency = $this->text->currencyMarked($m[1], Currency::Eur->value);
        $minor = $this->text->amountMinor($m[2], $currency);

        return $minor === null ? null : [-$minor, $currency];
    }

    // The reader's base is the last resort, for a figure the message put no
    // mark of any kind against: a PayPal total with no code and no glyph is
    // denominated by nothing the mail says.
    /**
     * @return array{int, string}|null
     */
    private function nativeFromLabelled(string $body): ?array
    {
        if (preg_match(self::labelledAmountRegex(), $body, $m) !== 1) {
            return null;
        }
        $currency = $this->text->currencyMarked($m[1], $this->baseCurrency->code());
        $minor = $this->text->amountMinor($m[2], $currency);

        return $minor === null ? null : [-$minor, $currency];
    }

    /**
     * @return array{int, string}
     */
    private function extractSettledLeg(string $body, string $nativeCurrency, int $nativeAmountMinor): array
    {
        if (preg_match(self::settledConversionRegex(), $body, $m) === 1) {
            $settledCurrency = strtoupper($m[1]);
            $settledRaw = $m[2];
            $minor = $this->text->amountMinor($settledRaw, $settledCurrency);
            if ($minor !== null && $settledCurrency !== $nativeCurrency) {
                return [-$minor, $settledCurrency];
            }
        }

        return [$nativeAmountMinor, $nativeCurrency];
    }
}
