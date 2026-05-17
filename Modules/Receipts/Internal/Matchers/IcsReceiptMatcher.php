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

/**
 * Sender-specific matcher for ICS Cards receipts.
 *
 * Claims any message whose sender's email-domain part is exactly
 * `ics.nl` or `icscards.nl` — extracted via `substr(strrchr(...), 1)`
 * for an exact equality comparison rather than `str_contains`, which
 * would silently accept the look-alike `noreply@ics.nl.attacker.example`.
 *
 * Body-extraction policy: ICS sends HTML-heavy receipts (occasionally
 * a multipart/alternative pair, more commonly a single text/html
 * part). The matcher prefers text/plain when present, else folds the
 * HTML body through `strip_tags(html_entity_decode(...))` so the regex
 * anchors bind against the rendered text rather than the markup.
 *
 * Anchors:
 *
 *  - merchant: `(Verkoper|Merchant): <name>` or the first non-empty
 *    `<td>` cell when the labelled form is absent.
 *  - amount: `EUR <amount>` or `€ <amount>` — Dutch comma decimal
 *    parsed via brick/money.
 *  - card last-four: `eindigend op <4-digit>` or
 *    `kaart **** <4-digit>` (with optional whitespace variations).
 *    When matched, populates a `FundedByCardPayload` on
 *    `ParsedReceiptDto.chainHints` so the downstream
 *    `ChainHintDetected` event can pair the receipt against the
 *    matching ICS card statement.
 *  - reference: `(Referentienummer|Autorisatiecode): <token>`.
 *
 * Sign convention: ICS receipts confirm OUTGOING charges, so the
 * extracted amount is NEGATED (e.g. `EUR 1,00` -> `-100`). Mirrors
 * the PayPal matcher's policy + the ICS PDF adapter's `Af` direction
 * convention so cross-format fingerprint parity holds.
 *
 * Date handling: the message's Date header is parsed via
 * `CarbonImmutable::parse` inside a `try/catch (Throwable)` and
 * normalised to `startOfDay()` — the cross-format fingerprint with
 * the corresponding ICS PDF row aligns because the PDF adapter
 * already normalises booked dates to startOfDay.
 *
 * PDF-attachment escape: when zbateson reports a PDF attachment AND
 * the inline body lacks any extractable amount anchor, the matcher
 * returns `MatchOutcomeDto::skipped('pdf_attachment_v2_only')` so the
 * row surfaces in the /inboxes skipped view rather than being
 * silently dropped or mis-parsed. The skip reason is a stable marker
 * for downstream telemetry; parsing the PDF body itself is not the
 * matcher's responsibility.
 *
 * Pure / stateless / singleton-safe — depends only on the injected
 * `EmlMimeReader`.
 */
final class IcsReceiptMatcher implements SenderMatcher
{
    /** Exact sender-domain equality defeats `ics.nl.attacker.example` spoofs. */
    private const ICS_DOMAINS = ['ics.nl', 'icscards.nl'];

    /** Merchant anchors: labelled form first, then loose `<td>` fallback. */
    private const MERCHANT_REGEX = '/(?:Verkoper|Merchant):\s*(.+)/i';

    /** Amount anchor: `EUR 1,00` or `€ 1,00` — Dutch comma decimal. */
    private const AMOUNT_REGEX = '/(?:€|EUR)\s*([0-9][0-9.,]*)/i';

    /**
     * Card last-four anchors. Accepts both the current-generation Dutch
     * "eindigend op 1234" form and the prior-generation "kaart **** 1234"
     * form. The 4-digit group is the captured last-four.
     */
    private const CARD_LAST4_REGEX = '/(?:eindigend op|kaart\s*\*{4})\s*([0-9]{4})/i';

    /** Reference anchor — labelled `Referentienummer` or `Autorisatiecode`. */
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

        // PDF-attachment escape: when zbateson reports a PDF attachment
        // AND the inline body fails amount extraction, the row is the
        // monthly-statement attachment shape (not a transactional
        // receipt); skip with a stable reason for downstream
        // telemetry.
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

        // Merchant: labelled form preferred; loose `<td>` fallback parses
        // the FIRST non-empty cell in the original HTML body when the
        // labelled form is absent (some prior-generation templates omit
        // the explicit `Verkoper:` label).
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
        // Negate: receipts confirm outgoing charges.
        $amountMinor = -$amountMinor;

        $reference = null;
        if (preg_match(self::REFERENCE_REGEX, $body, $referenceMatches) === 1) {
            $reference = $referenceMatches[1];
        }

        $cardLast4 = null;
        $cardMatchFull = null;
        $cardMatchOffset = null;
        if (preg_match(self::CARD_LAST4_REGEX, $body, $cardMatches, PREG_OFFSET_CAPTURE) === 1) {
            // PREG_OFFSET_CAPTURE returns each match as [string, int].
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
            // Snip a short evidence excerpt around the anchor so the
            // downstream ChainHintDetected event payload carries an
            // auditable provenance string. We already captured the
            // match offset above, so no second regex pass is needed.
            // $cardMatchFull and $cardMatchOffset are populated together
            // with $cardLast4 inside the preg_match branch above, so once
            // $cardLast4 !== null both are guaranteed to be set.
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

    /**
     * Decode HTML entities and strip tags so the regex anchors bind
     * against the rendered text rather than the markup. Collapses
     * internal whitespace so a token spanning multiple lines folds onto
     * a single line that the line-anchored regexes can match.
     */
    private function stripHtml(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $stripped = strip_tags($decoded);
        $collapsed = (string) preg_replace('/[ \t]+/', ' ', $stripped);

        return trim($collapsed);
    }

    /**
     * Pull the first non-empty `<td>` cell from a raw HTML body. Used
     * as the merchant fallback when the labelled `Verkoper:` /
     * `Merchant:` anchor is absent. Returns null when no cell text
     * surfaces.
     */
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

    /**
     * Parse a Dutch-comma OR US-period decimal amount into integer minor
     * units via brick/money. Returns null on parse failure so the caller
     * can return `MatchOutcomeDto::unmatched()`.
     */
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
