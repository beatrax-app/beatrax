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
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\ParsedMimeMessage;
use Throwable;

// Claims messages whose sender domain is exactly ics.nl or icscards.nl
// (exact equality, not str_contains, so a look-alike domain cannot
// spoof this matcher). Amounts are NEGATED and normalised to
// startOfDay() for cross-format fingerprint parity with the ICS PDF.
/**
 * @link ../../../../.docs/features/receipts/architecture.md
 */
final class IcsReceiptMatcher implements SenderMatcher
{
    private const ICS_DOMAINS = ['ics.nl', 'icscards.nl'];

    private const MERCHANT_REGEX = '/(?:Verkoper|Merchant):\s*(.+)/i';

    private const AMOUNT_REGEX = '/(?:€|EUR)\s*([0-9][0-9.,]*)/i';

    // Matches both the current-generation Dutch "eindigend op 1234"
    // form and the prior-generation "kaart **** 1234" form.
    private const CARD_LAST4_REGEX = '/(?:eindigend op|kaart\s*\*{4})\s*([0-9]{4})/i';

    private const REFERENCE_REGEX = '/(?:Referentienummer|Autorisatiecode):\s*([A-Z0-9]+)/i';

    public function __construct(private readonly EmlMimeReader $reader) {}

    public function key(): string
    {
        return 'ics-receipt';
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

        return in_array($domain, self::ICS_DOMAINS, true);
    }

    public function match(string $emlRaw): MatchOutcomeDto
    {
        $parsed = $this->reader->read($emlRaw);
        $body = $this->resolveBody($parsed);
        if ($body === '') {
            return MatchOutcomeDto::unmatched();
        }
        if ($this->isStatementShape($parsed, $body)) {
            return MatchOutcomeDto::skipped('pdf_attachment_v2_only');
        }

        return $this->parseReceipt($parsed, $body);
    }

    private function resolveBody(ParsedMimeMessage $parsed): string
    {
        $body = $parsed->textBody;
        if ($body === null || $body === '') {
            $body = $this->stripHtml($parsed->htmlBody ?? '');
        }

        return $body;
    }

    // A PDF attachment with no extractable inline amount is the
    // monthly-statement shape, not a transactional receipt — skip it
    // rather than mis-parsing it.
    private function isStatementShape(ParsedMimeMessage $parsed, string $body): bool
    {
        $hasPdfAttachment = false;
        foreach ($parsed->attachmentFilenames as $filename) {
            if (str_ends_with(strtolower($filename), '.pdf')) {
                $hasPdfAttachment = true;
                break;
            }
        }

        return $hasPdfAttachment && preg_match(self::AMOUNT_REGEX, $body) !== 1;
    }

    private function parseReceipt(ParsedMimeMessage $parsed, string $body): MatchOutcomeDto
    {
        $merchant = $this->extractMerchant($parsed, $body);
        if ($merchant === null || $merchant === '') {
            return MatchOutcomeDto::unmatched();
        }
        $amountMinor = $this->negatedAmountMinor($body);
        if ($amountMinor === null) {
            return MatchOutcomeDto::unmatched();
        }

        return $this->buildOutcome($parsed, $body, $merchant, $amountMinor);
    }

    // Merchant: labelled form preferred; loose <td> fallback parses the
    // first non-empty cell when older templates omit the label.
    private function extractMerchant(ParsedMimeMessage $parsed, string $body): ?string
    {
        if (preg_match(self::MERCHANT_REGEX, $body, $merchantMatches) === 1) {
            return trim($merchantMatches[1]);
        }
        if ($parsed->htmlBody !== null && $parsed->htmlBody !== '') {
            return $this->firstTableCell($parsed->htmlBody);
        }

        return null;
    }

    private function negatedAmountMinor(string $body): ?int
    {
        if (preg_match(self::AMOUNT_REGEX, $body, $amountMatches) !== 1) {
            return null;
        }
        $amountMinor = $this->toMinorOrNull($amountMatches[1], 'EUR');
        if ($amountMinor === null) {
            return null;
        }

        return -$amountMinor;
    }

    private function buildOutcome(ParsedMimeMessage $parsed, string $body, string $merchant, int $amountMinor): MatchOutcomeDto
    {
        $dateRaw = $parsed->headers['date'] ?? '';
        try {
            $bookedAt = CarbonImmutable::parse($dateRaw)->startOfDay();
        } catch (Throwable) {
            return MatchOutcomeDto::unmatched('invalid_date_header');
        }

        $dto = $this->buildDto($parsed, $body, $merchant, $amountMinor, $bookedAt);

        return MatchOutcomeDto::parsed($dto);
    }

    private function buildDto(
        ParsedMimeMessage $parsed,
        string $body,
        string $merchant,
        int $amountMinor,
        CarbonImmutable $bookedAt,
    ): ParsedReceiptDto {
        $reference = null;
        if (preg_match(self::REFERENCE_REGEX, $body, $referenceMatches) === 1) {
            $reference = $referenceMatches[1];
        }

        $cardLast4 = null;
        $chainHints = [];
        $chainEvidence = null;
        if (preg_match(self::CARD_LAST4_REGEX, $body, $cardMatches, PREG_OFFSET_CAPTURE) === 1) {
            $cardLast4 = $cardMatches[1][0];
            $chainHints[] = new FundedByCardPayload(cardLast4: $cardLast4);
            // The offset capture around the anchor snips the
            // audit-evidence excerpt without a second regex pass.
            $chainEvidence = trim(substr($body, max(0, $cardMatches[0][1] - 5), strlen($cardMatches[0][0]) + 10));
        }

        $rawPayload = [
            'matcher_key' => 'ics-receipt',
            'reference' => $reference,
            'card_last4' => $cardLast4,
            'subject' => $parsed->headers['subject'] ?? '',
            'sender' => $parsed->headers['from'] ?? '',
            'body_excerpt' => substr($body, 0, 200),
        ];
        if ($chainEvidence !== null) {
            $rawPayload['chain_hint_evidence'] = $chainEvidence;
        }

        return new ParsedReceiptDto(
            merchantName: $merchant,
            amountMinor: $amountMinor,
            currency: 'EUR',
            settledAmountMinor: $amountMinor,
            settledCurrency: 'EUR',
            referenceId: $reference,
            bookedAt: $bookedAt,
            ownIban: 'ICS-CARD',
            description: $merchant,
            rawPayload: $rawPayload,
            chainHints: $chainHints,
        );
    }

    private function stripHtml(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $stripped = strip_tags($decoded);
        $collapsed = (string) preg_replace('/[ \t]+/', ' ', $stripped);

        return trim($collapsed);
    }

    private function firstTableCell(string $html): ?string
    {
        if (preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $html, $matches) === false) {
            return null;
        }
        foreach ($matches[1] as $cell) {
            $text = trim(strip_tags(html_entity_decode($cell, ENT_QUOTES | ENT_HTML5)));
            if ($text !== '') {
                return $text;
            }
        }

        return null;
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
