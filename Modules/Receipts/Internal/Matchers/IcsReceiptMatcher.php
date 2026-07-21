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

        $body = $parsed->textBody;
        if ($body === null || $body === '') {
            $body = $this->stripHtml($parsed->htmlBody ?? '');
        }
        if ($body === '') {
            return MatchOutcomeDto::unmatched();
        }

        // A PDF attachment with no extractable inline amount is the
        // monthly-statement shape, not a transactional receipt — skip
        // with a stable reason rather than mis-parsing it.
        $hasPdfAttachment = false;
        foreach ($parsed->attachmentFilenames as $filename) {
            if (str_ends_with(strtolower($filename), '.pdf')) {
                $hasPdfAttachment = true;
                break;
            }
        }
        if (
            $hasPdfAttachment
            && preg_match(self::AMOUNT_REGEX, $body) !== 1
        ) {
            return MatchOutcomeDto::skipped('pdf_attachment_v2_only');
        }

        // Merchant: labelled form preferred; loose <td> fallback parses
        // the first non-empty cell when older templates omit the label.
        $merchant = null;
        if (preg_match(self::MERCHANT_REGEX, $body, $merchantMatches) === 1) {
            $merchant = trim($merchantMatches[1]);
        } elseif ($parsed->htmlBody !== null && $parsed->htmlBody !== '') {
            $merchant = $this->firstTableCell($parsed->htmlBody);
        }
        if ($merchant === null || $merchant === '') {
            return MatchOutcomeDto::unmatched();
        }

        if (preg_match(self::AMOUNT_REGEX, $body, $amountMatches) !== 1) {
            return MatchOutcomeDto::unmatched();
        }
        $amountMinor = $this->toMinorOrNull($amountMatches[1], 'EUR');
        if ($amountMinor === null) {
            return MatchOutcomeDto::unmatched();
        }
        $amountMinor = -$amountMinor;

        $reference = null;
        if (preg_match(self::REFERENCE_REGEX, $body, $referenceMatches) === 1) {
            $reference = $referenceMatches[1];
        }

        $cardLast4 = null;
        $cardMatchFull = null;
        $cardMatchOffset = null;
        if (preg_match(self::CARD_LAST4_REGEX, $body, $cardMatches, PREG_OFFSET_CAPTURE) === 1) {
            $cardLast4 = $cardMatches[1][0];
            $cardMatchFull = $cardMatches[0][0];
            $cardMatchOffset = $cardMatches[0][1];
        }

        $dateRaw = $parsed->headers['date'] ?? '';
        try {
            $bookedAt = CarbonImmutable::parse($dateRaw)->startOfDay();
        } catch (Throwable) {
            return MatchOutcomeDto::unmatched('invalid_date_header');
        }

        $chainHints = [];
        $chainEvidence = null;
        if ($cardLast4 !== null) {
            $chainHints[] = new FundedByCardPayload(cardLast4: $cardLast4);
            // $cardMatchFull/$cardMatchOffset are always set alongside
            // $cardLast4 above, so a fresh regex pass isn't needed to
            // snip the audit-evidence excerpt around the anchor.
            $chainEvidence = trim(substr($body, max(0, $cardMatchOffset - 5), strlen($cardMatchFull) + 10));
        }

        $subject = $parsed->headers['subject'] ?? '';
        $sender = $parsed->headers['from'] ?? '';

        $rawPayload = [
            'matcher_key' => 'ics-receipt',
            'reference' => $reference,
            'card_last4' => $cardLast4,
            'subject' => $subject,
            'sender' => $sender,
            'body_excerpt' => substr($body, 0, 200),
        ];
        if ($chainEvidence !== null) {
            $rawPayload['chain_hint_evidence'] = $chainEvidence;
        }

        $dto = new ParsedReceiptDto(
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

        return MatchOutcomeDto::parsed($dto);
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
            $money = Money::of(BigDecimal::of($normalised), $currency, roundingMode: RoundingMode::HALF_UP);
        } catch (NumberFormatException|UnknownCurrencyException) {
            return null;
        }

        return $money->getMinorAmount()->toInt();
    }
}
