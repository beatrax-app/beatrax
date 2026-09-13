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
use Modules\Receipts\Public\Dto\ChainHintPayload\RefundOfPayload;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\ParsedMimeMessage;

// Claims messages whose sender domain is exactly ics.nl or icscards.nl
// (exact equality, not str_contains, so a look-alike domain cannot
// spoof this matcher). Dates are normalised to startOfDay() for
// cross-format fingerprint parity with the ICS PDF.
/**
 * @link ../../../../.docs/features/receipts/architecture.md#a-direction-nothing-states-is-not-a-charge
 */
final readonly class IcsReceiptMatcher implements SenderMatcher
{
    private const string MATCHER_KEY = 'ics-receipt';

    private const array ICS_DOMAINS = ['ics.nl', 'icscards.nl'];

    private const string MERCHANT_REGEX = '/(?:Verkoper|Merchant):\s*(.+)/i';

    // Matches both the current-generation Dutch "eindigend op 1234"
    // form and the prior-generation "kaart **** 1234" form.
    private const string CARD_LAST4_REGEX = '/(?:eindigend op|kaart\s*\*{4})\s*([0-9]{4})/i';

    private const string REFERENCE_REGEX = '/(?:Referentienummer|Autorisatiecode):\s*([A-Z0-9]+)/i';

    // The purchase a credit reverses, named as the thing it reverses rather
    // than as a second reference number: the label above matches on a
    // substring, so one spelled `Oorspronkelijk referentienummer` would be
    // read as the credit's own.
    private const string ORIGINAL_REFERENCE_REGEX = '/(?:Oorspronkelijke transactie|Original transaction):\s*([A-Z0-9]+)/i';

    // The labels ICS prints in front of the figure the card was charged. A
    // notification states the reader's spending limit too, and that figure is
    // denominated exactly like the charge.
    private const string TOTAL_LABELS = 'Bedrag|Amount';

    // The euro column the card statement prints beside a foreign figure, which
    // is the movement the account made. The card settles in euros, so a leg
    // denominated in the currency the merchant billed is not the settled one.
    private const string EURO_LEG_LABELS = "Bedrag in euro's|Bedrag in euro\u{2019}s|Bedrag in euro|Amount in euros";

    private const string DIRECTION_OWED_TO_ICS = 'Af';

    // Words that can only be read as money coming back, used to REFUSE and
    // never to book: none of them is verified in an ICS notification, so a
    // body carrying one with no marker beside its figure is withheld rather
    // than charged. Being wrong about a word then costs a miss, not a sign.
    private const string CREDIT_VOCABULARY_REGEX = '/\b(?:terugbetaling|terugbetaald|terugbetalen|creditering|creditnota|gecrediteerd|storno|gestorneerd|terugboeking|teruggeboekt|terugstorting|teruggestort|retour|terugvragen|refund(?:ed)?|chargeback|terugvordering)\b/iu';

    // The one direction word a notification has been shown to carry outside
    // the marker. A purchase is money out in every reading; a word that can
    // name either direction is deliberately not on this list.
    private const string PURCHASE_VOCABULARY_REGEX = '/\baankoop\b/iu';

    private const string DIRECTION_UNSTATED_REASON = 'ics_direction_unstated';

    private const string EURO_LEG_UNSTATED_REASON = 'ics_euro_leg_unstated';

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
        return '/'.ReceiptBodyText::underLabel(self::TOTAL_LABELS, ReceiptBodyText::markedAmount()).'/i';
    }

    private static function euroLegRegex(): string
    {
        return '/'.ReceiptBodyText::underLabel(self::EURO_LEG_LABELS, ReceiptBodyText::markedAmount()).'/i';
    }

    // ICS prints every figure positive and states its direction beside it, at
    // the row's end. Case-sensitive and anchored on the line end, because
    // `bij` is also the commonest Dutch preposition, and `Bedrag: € 12,00 bij
    // Albert Heijn` is a purchase.
    private static function directionRegex(): string
    {
        return '/'.ReceiptBodyText::underLabel(self::TOTAL_LABELS, ReceiptBodyText::markedAmount())
            .'[ \t]*(?:(?:'.ReceiptBodyText::currencyMarkers().')[ \t]*)?(?-i:(Af|Bij))[ \t]*\r?$/im';
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
        if (preg_match(self::amountRegex(), $body, $amountMatches) !== 1) {
            return MatchOutcomeDto::unmatched();
        }

        return $this->directedCharge($parsed, $body, $merchant, $amountMatches[1], $amountMatches[2]);
    }

    // The two questions the figure itself answers: what money it is in, and
    // which way it moved. Its own body because the second is a decision rather
    // than a read, and a notification that does not answer it is not a charge.
    private function directedCharge(
        ParsedMimeMessage $parsed,
        string $body,
        string $merchant,
        string $mark,
        string $figure,
    ): MatchOutcomeDto {
        $currency = $this->text->currencyMarked($mark, Currency::Eur->value);
        $magnitude = $this->text->amountMinor($figure, $currency);
        if ($magnitude === null) {
            return MatchOutcomeDto::unmatched();
        }
        $sign = $this->directionSign($body, $parsed->headers['subject'] ?? '');
        if ($sign === null) {
            return MatchOutcomeDto::unmatched(self::DIRECTION_UNSTATED_REASON);
        }

        return $this->buildOutcome($parsed, $body, $merchant, [$sign * $magnitude, $currency]);
    }

    // The marker beside the figure is the statement's own grammar and answers
    // first. Only where the notification prints none does the wording decide,
    // and then only in the direction each word can only mean -- the subject
    // included, because that is where the older template states its own.
    private function directionSign(string $body, string $subject): ?int
    {
        if (preg_match(self::directionRegex(), $body, $markerMatches) === 1) {
            return $markerMatches[3] === self::DIRECTION_OWED_TO_ICS ? -1 : 1;
        }
        $stated = $subject."\n".$body;
        if (preg_match(self::CREDIT_VOCABULARY_REGEX, $stated) === 1) {
            return null;
        }

        return preg_match(self::PURCHASE_VOCABULARY_REGEX, $stated) === 1 ? -1 : null;
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
     * @param  array{int, string}  $charge
     */
    private function buildOutcome(ParsedMimeMessage $parsed, string $body, string $merchant, array $charge): MatchOutcomeDto
    {
        $settled = $this->settledLeg($body, $charge);
        if ($settled === null) {
            return MatchOutcomeDto::unmatched(self::EURO_LEG_UNSTATED_REASON);
        }

        $bookedAt = SafeDate::normalizedDayOrNull($parsed->headers['date'] ?? '');
        if ($bookedAt === null) {
            return MatchOutcomeDto::unmatched('invalid_date_header');
        }

        $dto = $this->buildDto($parsed, $body, $merchant, $charge, $settled, $bookedAt);

        return MatchOutcomeDto::parsed($dto);
    }

    // The card bills in euros, so the euro figure IS the movement and a
    // foreign one is only what the merchant asked for. A notification that
    // states no euro leg for a foreign charge does not state the movement at
    // all, and the statement PDF is the source that does.
    /**
     * @param  array{int, string}  $charge
     * @return array{int, string}|null
     */
    private function settledLeg(string $body, array $charge): ?array
    {
        [$amountMinor, $currency] = $charge;
        if ($currency === Currency::Eur->value) {
            return [$amountMinor, $currency];
        }
        $euroMinor = $this->euroLegMinor($body);
        if ($euroMinor === null) {
            return null;
        }

        // One movement written in two denominations carries one direction, so
        // the leg takes the charge's sign rather than being read for its own.
        return [$amountMinor < 0 ? -$euroMinor : $euroMinor, Currency::Eur->value];
    }

    // The figure under the mail's euro label, in minor units. Null where the
    // message prints no such line, and null where what it printed there is
    // marked as some other money -- a label naming the euro does not make the
    // figure beneath it one.
    private function euroLegMinor(string $body): ?int
    {
        if (preg_match(self::euroLegRegex(), $body, $euroMatches) !== 1) {
            return null;
        }
        if ($this->text->currencyMarked($euroMatches[1], Currency::Eur->value) !== Currency::Eur->value) {
            return null;
        }

        return $this->text->amountMinor($euroMatches[2], Currency::Eur->value);
    }

    /**
     * @param  array{int, string}  $charge
     * @param  array{int, string}  $settled
     */
    private function buildDto(
        ParsedMimeMessage $parsed,
        string $body,
        string $merchant,
        array $charge,
        array $settled,
        CarbonImmutable $bookedAt,
    ): ParsedReceiptDto {
        [$amountMinor, $currency] = $charge;
        [$settledAmountMinor, $settledCurrency] = $settled;

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
            $chainEvidence = trim(mb_strcut($body, max(0, $cardMatches[0][1] - 5), strlen($cardMatches[0][0]) + 10));
        }
        // A credit naming the purchase it reverses is the pairing B5 resolves
        // against transactions.source_ref; ICS statement rows carry none, so
        // the original has to have been read from a notification too.
        if ($amountMinor > 0 && preg_match(self::ORIGINAL_REFERENCE_REGEX, $body, $originalMatches) === 1) {
            $chainHints[] = new RefundOfPayload(originalReferenceId: $originalMatches[1]);
        }

        $rawPayload = [
            'reference' => $reference,
            'card_last4' => $cardLast4,
            'subject' => $parsed->headers['subject'] ?? '',
            'sender' => $parsed->headers['from'] ?? '',
            'body_excerpt' => mb_strcut($body, 0, 200),
        ];
        if ($chainEvidence !== null) {
            $rawPayload['chain_hint_evidence'] = $chainEvidence;
        }

        return new ParsedReceiptDto(
            merchantName: $merchant,
            amountMinor: $amountMinor,
            currency: $currency,
            settledAmountMinor: $settledAmountMinor,
            settledCurrency: $settledCurrency,
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
