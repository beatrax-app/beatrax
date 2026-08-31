<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Matchers;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\SafeDate;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\ParsedMimeMessage;

// Claims messages whose sender domain is exactly ics.nl or icscards.nl
// (exact equality, not str_contains, so a look-alike domain cannot
// spoof this matcher). Amounts are NEGATED and normalised to
// startOfDay() for cross-format fingerprint parity with the ICS PDF.
final readonly class IcsReceiptMatcher implements SenderMatcher
{
    private const string MATCHER_KEY = 'ics-receipt';

    private const array ICS_DOMAINS = ['ics.nl', 'icscards.nl'];

    private const string MERCHANT_REGEX = '/(?:Verkoper|Merchant):\s*(.+)/i';

    // Matches both the current-generation Dutch "eindigend op 1234"
    // form and the prior-generation "kaart **** 1234" form.
    private const string CARD_LAST4_REGEX = '/(?:eindigend op|kaart\s*\*{4})\s*([0-9]{4})/i';

    private const string REFERENCE_REGEX = '/(?:Referentienummer|Autorisatiecode):\s*([A-Z0-9]+)/i';

    public function __construct(
        private EmlMimeReader $reader,
        private ReceiptBodyText $text,
    ) {}

    // The anchor captures the mark it found instead of naming the euro twice —
    // once in this pattern and once as the code the digits were then read at.
    // A card billed abroad quotes the foreign figure, and at the euro's scale
    // a yen line read as a hundredth of itself.
    private static function amountRegex(): string
    {
        return '/('.ReceiptBodyText::currencyMarkers().')\s*([0-9][0-9.,]*)/i';
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
            return $this->text->plainText($parsed->htmlBody ?? '');
        }

        return $body;
    }

    // A PDF attachment with no extractable inline amount is the
    // monthly-statement shape, not a transactional receipt — skip it
    // rather than mis-parsing it.
    private function isStatementShape(ParsedMimeMessage $parsed, string $body): bool
    {
        $hasPdfAttachment = array_any($parsed->attachmentFilenames, fn (string $filename): bool => str_ends_with(strtolower($filename), '.pdf'));

        return $hasPdfAttachment && preg_match(self::amountRegex(), $body) !== 1;
    }

    private function parseReceipt(ParsedMimeMessage $parsed, string $body): MatchOutcomeDto
    {
        $merchant = $this->extractMerchant($parsed, $body);
        if ($merchant === null || $merchant === '') {
            return MatchOutcomeDto::unmatched();
        }
        $charge = $this->negatedCharge($body);
        if ($charge === null) {
            return MatchOutcomeDto::unmatched();
        }

        return $this->buildOutcome($parsed, $body, $merchant, $charge);
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

    /**
     * @return array{int, string}|null
     */
    private function negatedCharge(string $body): ?array
    {
        if (preg_match(self::amountRegex(), $body, $amountMatches) !== 1) {
            return null;
        }
        $currency = $this->text->currencyMarked($amountMatches[1], Currency::Eur->value);
        $amountMinor = $this->text->amountMinor($amountMatches[2], $currency);
        if ($amountMinor === null) {
            return null;
        }

        return [-$amountMinor, $currency];
    }

    /**
     * @param  array{int, string}  $charge
     */
    private function buildOutcome(ParsedMimeMessage $parsed, string $body, string $merchant, array $charge): MatchOutcomeDto
    {
        $bookedAt = SafeDate::normalisedDayOrNull($parsed->headers['date'] ?? '');
        if ($bookedAt === null) {
            return MatchOutcomeDto::unmatched('invalid_date_header');
        }

        $dto = $this->buildDto($parsed, $body, $merchant, $charge, $bookedAt);

        return MatchOutcomeDto::parsed($dto);
    }

    /**
     * @param  array{int, string}  $charge
     */
    private function buildDto(
        ParsedMimeMessage $parsed,
        string $body,
        string $merchant,
        array $charge,
        CarbonImmutable $bookedAt,
    ): ParsedReceiptDto {
        [$amountMinor, $currency] = $charge;

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
            currency: $currency,
            settledAmountMinor: $amountMinor,
            settledCurrency: $currency,
            referenceId: $reference,
            bookedAt: $bookedAt,
            ownIban: SyntheticIban::IcsCard->value,
            description: $merchant,
            rawPayload: $rawPayload,
            chainHints: $chainHints,
        );
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
}
