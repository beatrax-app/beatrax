<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

final class IcsPdfExtractionMap
{
    public const TRANSACTIONS_TABLE_ANCHOR = 'transactie boeking';

    /**
     * @var list<string>
     */
    public const PAGE_NOISE_PATTERNS = [
        '/^\s*KAARTHOUDER.*$/m',
        '/^\s*Uw Card met als laatste vier cijfers .*$/m',
        '/^\s*Datum\s+ICS-klantnummer\s+Volgnummer\s+Bladnummer\s*$/m',
        '/^.*Nu beschikbaar: Apple Pay!.*$/m',
        '/^.*Dit product valt onder het depositogarantiestelsel.*$/m',
        '/^.*Het minimaal te betalen bedrag ad.*$/m',
        '/^.*Uw betalingen aan International Card Services BV.*$/m',
    ];

    // Prefixes the continuation line of a two-line foreign-currency row.
    public const FX_LINE_ANCHOR = 'Wisselkoers ';

    public const CARD_LAST4_LINE_PREFIX = 'Uw Card met als laatste vier cijfers ';

    public const SUMMARY_OPENING = 'Vorig openstaand saldo';

    public const SUMMARY_RECEIVED = 'Totaal ontvangen betalingen';

    public const SUMMARY_CHARGES = 'Totaal nieuwe uitgaven';

    public const SUMMARY_CLOSING = 'Nieuw openstaand saldo';

    public const SUMMARY_CREDIT_LIMIT = 'Bestedingslimiet';

    public const SUMMARY_MIN_DUE = 'Minimaal te betalen bedrag';

    /**
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
