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
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\ParsedMimeMessage;
use Throwable;

// Claims messages whose sender domain is exactly paypal.com (exact
// suffix equality, not str_contains, defeats a spoofed look-alike
// domain). Amounts are NEGATED (receipts confirm outgoing payments); a
// sign-in-notification subject skips rather than treats it as a receipt.
/**
 * @link ../../../../.docs/features/receipts/architecture.md
 */
final class PaypalReceiptMatcher implements SenderMatcher
{
    private const PAYPAL_DOMAIN = 'paypal.com';

    private const LOGIN_NOTIFICATION_SUBJECT_REGEX = '/(new (device )?sign-in|new login|inloggen op een nieuw apparaat)/i';

    private const TRANSACTION_ID_REGEX = '/Transaction ID:\s*([A-Z0-9]{17})/i';

    private const EUR_AMOUNT_REGEX = '/EUR\s+([0-9.,]+)/i';

    private const USD_AMOUNT_REGEX = '/\$\s*([0-9.,]+)\s*USD/i';

    private const LABELLED_AMOUNT_REGEX = '/(?:Bedrag|Amount|Total):\s*([A-Z]{3})?\s*([0-9.,]+)/i';

    // Accepts an optional repeated currency token or symbol before the
    // amount, both of which PayPal has been observed emitting.
    private const SETTLED_CONVERSION_REGEX = '/Conversion to ([A-Z]{3}):?\s*(?:[€$£]\s*|[A-Z]{3}\s+)?([0-9.,]+)/i';

    private const MERCHANT_REGEX = '/(?:Aan|Merchant|To|Paid to):\s*(.+)/i';

    public function __construct(
        private readonly EmlMimeReader $reader,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    public function key(): string
    {
        return 'paypal-receipt';
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

        $dateRaw = $parsed->headers['date'] ?? '';
        try {
            $bookedAt = CarbonImmutable::parse($dateRaw)->startOfDay();
        } catch (Throwable) {
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
            ownIban: 'PAYPAL',
            description: $merchant,
            rawPayload: [
                'matcher_key' => 'paypal-receipt',
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
        // its anchor is more specific than the bare EUR anchor.
        return $this->nativeFromUsd($body)
            ?? $this->nativeFromEur($body)
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
        $minor = $this->toMinorOrNull($m[1], 'USD');

        return $minor === null ? null : [-$minor, 'USD'];
    }

    /**
     * @return array{int, string}|null
     */
    private function nativeFromEur(string $body): ?array
    {
        if (preg_match(self::EUR_AMOUNT_REGEX, $body, $m) !== 1) {
            return null;
        }
        $minor = $this->toMinorOrNull($m[1], 'EUR');

        return $minor === null ? null : [-$minor, 'EUR'];
    }

    /**
     * @return array{int, string}|null
     */
    private function nativeFromLabelled(string $body): ?array
    {
        if (preg_match(self::LABELLED_AMOUNT_REGEX, $body, $m) !== 1) {
            return null;
        }
        $currency = $m[1] !== '' ? strtoupper($m[1]) : $this->baseCurrency->code();
        $minor = $this->toMinorOrNull($m[2], $currency);

        return $minor === null ? null : [-$minor, $currency];
    }

    /**
     * @return array{int, string}
     */
    private function extractSettledLeg(string $body, string $nativeCurrency, int $nativeAmountMinor): array
    {
        if (preg_match(self::SETTLED_CONVERSION_REGEX, $body, $m) === 1) {
            $settledCurrency = strtoupper($m[1]);
            $settledRaw = $m[2];
            $minor = $this->toMinorOrNull($settledRaw, $settledCurrency);
            if ($minor !== null && $settledCurrency !== $nativeCurrency) {
                return [-$minor, $settledCurrency];
            }
        }

        return [$nativeAmountMinor, $nativeCurrency];
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
            $money = Money::of(BigDecimal::of($normalised), $currency, roundingMode: RoundingMode::HalfUp);
        } catch (NumberFormatException|UnknownCurrencyException) {
            return null;
        }

        return $money->getMinorAmount()->toInt();
    }
}
