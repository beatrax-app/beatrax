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
use Modules\Receipts\Internal\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Throwable;

/**
 * Sender-specific matcher for PayPal receipts (`service@paypal.com`).
 *
 * Claims any message whose sender's email-domain part is exactly
 * `paypal.com` — extracted via `substr(strrchr(...), 1)` for an exact
 * suffix comparison rather than `str_contains`, which would silently
 * accept the look-alike `service@paypal.com.attacker.example`.
 *
 * Body-extraction policy mirrors `EmlMimeReader`: text/plain preferred,
 * text/html fallback only when text/plain is null. PayPal sends
 * `multipart/alternative` for every receipt, so the text/plain part is
 * always available in practice.
 *
 * The matcher recognises three body shapes:
 *
 *  - Current-generation Dutch receipts — `Aan: <merchant>` line plus
 *    a `Bedrag: EUR <amount,99>` or bare `EUR <amount,99>` line.
 *  - Prior-generation English receipts — `Merchant: <merchant>` and
 *    `Amount: EUR <amount>` anchors.
 *  - PayPal login notifications — `New device sign-in` / `New sign-in`
 *    subjects, body lacking a Transaction ID. Returns
 *    `MatchOutcomeDto::skipped('paypal-login-notification')`.
 *
 * Multi-currency: when both a native (e.g. `$ 12.99 USD`) and a settled
 * leg (`Conversion to EUR: EUR 11,82`) appear in the body, both legs
 * are surfaced via `ParsedReceiptDto.amountMinor + currency` (native)
 * and `settledAmountMinor + settledCurrency` (settled). EUR-only
 * receipts mirror the native pair into the settled pair so the
 * downstream `SourceTransactionDto` contract sees non-null settled
 * fields on every row.
 *
 * Amount sign convention: receipts confirm an OUTGOING payment, so the
 * extracted amount is negated (`-1299` for `EUR 12,99`). Refunds are
 * not yet recognised by this matcher; a future arm would inspect the
 * subject for a `refund` anchor and flip the sign.
 *
 * Date handling: the message's `Date` header is parsed via
 * `CarbonImmutable::parse` inside a `try/catch (Throwable)` and
 * normalised to `startOfDay()` so the cross-format fingerprint with
 * the corresponding PayPal CSV row aligns (the CSV adapter normalises
 * via `PaypalDateParser` the same way). An unparseable Date header
 * surfaces as `MatchOutcomeDto::unmatched('invalid_date_header')` —
 * the consumer transitions the row to `status='unmatched'` rather
 * than blowing up the dispatch loop.
 *
 * Pure / stateless / singleton-safe — depends only on the injected
 * `EmlMimeReader` (itself a singleton facade over zbateson).
 */
final class PaypalReceiptMatcher implements SenderMatcher
{
    /** Exact sender-domain match defeats `paypal.com.attacker.example` spoofs. */
    private const PAYPAL_DOMAIN = 'paypal.com';

    /**
     * Subject anchors that signal a non-transactional PayPal email
     * (sign-in notice, password change, marketing). Matched
     * case-insensitively.
     */
    private const LOGIN_NOTIFICATION_SUBJECT_REGEX = '/(new (device )?sign-in|new login|inloggen op een nieuw apparaat)/i';

    /** Transaction ID anchor — exactly 17 alphanumeric characters. */
    private const TRANSACTION_ID_REGEX = '/Transaction ID:\s*([A-Z0-9]{17})/i';

    /** Native EUR amount with Dutch comma decimal. */
    private const EUR_AMOUNT_REGEX = '/EUR\s+([0-9.,]+)/i';

    /** Native USD amount with US period decimal (e.g. `$ 12.99 USD`). */
    private const USD_AMOUNT_REGEX = '/\$\s*([0-9.,]+)\s*USD/i';

    /** Labelled amount fallback (`Bedrag: EUR 12,99`, `Amount: EUR 12,99`). */
    private const LABELLED_AMOUNT_REGEX = '/(?:Bedrag|Amount|Total):\s*([A-Z]{3})?\s*([0-9.,]+)/i';

    /**
     * Settled-leg anchor — `Conversion to <ISO>: <amount>`. Accepts an
     * optional repeated currency token (`Conversion to EUR: EUR 11,82`)
     * or symbol (`Conversion to EUR: € 11,82`) before the amount, both
     * of which PayPal has been observed emitting.
     */
    private const SETTLED_CONVERSION_REGEX = '/Conversion to ([A-Z]{3}):?\s*(?:[€$£]\s*|[A-Z]{3}\s+)?([0-9.,]+)/i';

    /** Merchant-name anchor — both Dutch and English variants. */
    private const MERCHANT_REGEX = '/(?:Aan|Merchant|To|Paid to):\s*(.+)/i';

    public function __construct(private readonly EmlMimeReader $reader) {}

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

        if (preg_match(self::TRANSACTION_ID_REGEX, $body, $txMatches) !== 1) {
            return MatchOutcomeDto::unmatched();
        }
        $transactionId = $txMatches[1];

        $amountPair = $this->extractNativeAmount($body);
        if ($amountPair === null) {
            return MatchOutcomeDto::unmatched();
        }
        [$nativeAmountMinor, $nativeCurrency] = $amountPair;

        $settled = $this->extractSettledLeg($body, $nativeCurrency, $nativeAmountMinor);
        [$settledAmountMinor, $settledCurrency] = $settled;

        if (preg_match(self::MERCHANT_REGEX, $body, $merchantMatches) !== 1) {
            return MatchOutcomeDto::unmatched();
        }
        $merchant = trim($merchantMatches[1]);
        if ($merchant === '') {
            return MatchOutcomeDto::unmatched();
        }

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
     * Extract the native amount + currency. Returns `[amountMinor, currency]`
     * or `null` when no recognisable amount anchor is present. The returned
     * minor amount is NEGATED — PayPal receipts confirm outgoing payments,
     * so the canonical sign is negative.
     *
     * @return array{int, string}|null
     */
    private function extractNativeAmount(string $body): ?array
    {
        // USD first when both `$ X USD` and `EUR Y` appear (foreign-currency
        // receipt shape: native USD, settled EUR). The USD anchor is more
        // specific (it includes both `$` and `USD` tokens) so a positive
        // USD match takes precedence over the bare EUR anchor.
        if (preg_match(self::USD_AMOUNT_REGEX, $body, $m) === 1) {
            $minor = $this->toMinorOrNull($m[1], 'USD');
            if ($minor !== null) {
                return [-$minor, 'USD'];
            }
        }

        if (preg_match(self::EUR_AMOUNT_REGEX, $body, $m) === 1) {
            $minor = $this->toMinorOrNull($m[1], 'EUR');
            if ($minor !== null) {
                return [-$minor, 'EUR'];
            }
        }

        if (preg_match(self::LABELLED_AMOUNT_REGEX, $body, $m) === 1) {
            $currency = $m[1] !== '' ? strtoupper($m[1]) : 'EUR';
            $minor = $this->toMinorOrNull($m[2], $currency);
            if ($minor !== null) {
                return [-$minor, $currency];
            }
        }

        return null;
    }

    /**
     * Extract the settled-leg amount + currency when a separate
     * `Conversion to <ISO>: <amount>` line is present. Returns
     * `[settledAmountMinor, settledCurrency]`. When no second-currency
     * leg appears (the common Dutch EUR-only case) the native pair is
     * mirrored so the downstream `SourceTransactionDto` contract sees
     * non-null settled fields on every row.
     *
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

    /**
     * Parse a Dutch-comma OR US-period decimal amount into integer minor
     * units via brick/money. Returns null on parse failure so the caller
     * can fall through to the next anchor.
     */
    private function toMinorOrNull(string $raw, string $currency): ?int
    {
        $normalised = trim($raw);
        // Normalise: if both '.' and ',' appear assume Dutch (`.` thousands,
        // `,` decimal) and strip the dots. Otherwise convert a lone ','
        // to '.' so brick/money's BigDecimal parser accepts it.
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
