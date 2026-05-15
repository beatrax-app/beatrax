<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

/**
 * Anchor tokens + per-page noise regexes + FX-row pattern for the Mijn ICS
 * consumer-portal monthly-statement PDF layout, drawn empirically from a
 * real anonymised statement fixture under
 * Modules/Ingestion/tests/fixtures/ics/.
 *
 * Token shapes are revolving-credit-statement nomenclature
 * (`Vorig openstaand saldo` / `Totaal ontvangen betalingen` /
 * `Totaal nieuwe uitgaven` / `Nieuw openstaand saldo` /
 * `Bestedingslimiet` / `Minimaal te betalen bedrag`) — what the Mijn
 * ICS consumer portal emits on real statements.
 */
final class IcsPdfExtractionMap
{
    /**
     * Anchor sub-header line for the transactions-table region. The
     * line `transactie boeking` appears once per page directly below
     * the four-column header (`Datum  Datum  Omschrijving  Bedrag…`).
     * Locating this substring is the most reliable way to scan to the
     * first transaction row regardless of whether the header columns
     * shifted leftward by one or two whitespace cells between pages.
     */
    public const TRANSACTIONS_TABLE_ANCHOR = 'transactie boeking';

    /**
     * Page-noise patterns stripped before the transactions-table iteration
     * begins. Empirical, drawn from the Mijn ICS fixture's "Per-page noise
     * patterns" record section.
     *
     * @var list<string>
     */
    public const PAGE_NOISE_PATTERNS = [
        // Cardholder banner (recurs at the top of every transactions page).
        '/^\s*KAARTHOUDER.*$/m',
        // Card watermark + canonical masked-PAN placeholder.
        '/^\s*Uw Card met als laatste vier cijfers .*$/m',
        // Statement-summary repeating header block (two consecutive lines).
        '/^\s*Datum\s+ICS-klantnummer\s+Volgnummer\s+Bladnummer\s*$/m',
        // Apple Pay marketing banner.
        '/^.*Nu beschikbaar: Apple Pay!.*$/m',
        // Depositogarantiestelsel disclaimer.
        '/^.*Dit product valt onder het depositogarantiestelsel.*$/m',
        // Body paragraph: due-date directive.
        '/^.*Het minimaal te betalen bedrag ad.*$/m',
        // Body paragraph: payments-updated-as-of directive.
        '/^.*Uw betalingen aan International Card Services BV.*$/m',
    ];

    /**
     * Wisselkoers anchor — the second line of a two-line FX-row block.
     * The first line carries the merchant + native amount + settled-EUR
     * amount; the second line begins with this literal followed by the
     * native currency code and the displayed conversion rate.
     */
    public const FX_LINE_ANCHOR = 'Wisselkoers ';

    /** Token marking the row where the card last-four is rendered. */
    public const CARD_LAST4_LINE_PREFIX = 'Uw Card met als laatste vier cijfers ';

    // Statement-summary anchor tokens (revolving-credit nomenclature).

    /** Opening-balance header token. */
    public const SUMMARY_OPENING = 'Vorig openstaand saldo';

    /** Period-total credits header token. */
    public const SUMMARY_RECEIVED = 'Totaal ontvangen betalingen';

    /** Period-total charges header token. */
    public const SUMMARY_CHARGES = 'Totaal nieuwe uitgaven';

    /** Closing-balance header token. */
    public const SUMMARY_CLOSING = 'Nieuw openstaand saldo';

    /** Credit-limit informational token. */
    public const SUMMARY_CREDIT_LIMIT = 'Bestedingslimiet';

    /** Minimum-due informational token. */
    public const SUMMARY_MIN_DUE = 'Minimaal te betalen bedrag';

    /**
     * The six empirically-confirmed summary tokens in the same order they
     * appear in the statement-summary value row on page 1.
     *
     * @var list<string>
     */
    public const SUMMARY_TOKENS = [
        self::SUMMARY_OPENING,
        self::SUMMARY_RECEIVED,
        self::SUMMARY_CHARGES,
        self::SUMMARY_CLOSING,
        self::SUMMARY_CREDIT_LIMIT,
        self::SUMMARY_MIN_DUE,
    ];
}
