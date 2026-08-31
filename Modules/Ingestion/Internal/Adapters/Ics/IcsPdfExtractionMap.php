<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

final class IcsPdfExtractionMap
{
    /**
     * @var list<string>
     */
    public const array PAGE_NOISE_PATTERNS = [
        '/^\s*KAARTHOUDER.*$/m',
        '/^\s*Uw Card met als laatste vier cijfers .*$/m',
        '/^\s*Datum\s+ICS-klantnummer\s+Volgnummer\s+Bladnummer\s*$/m',
        '/^.*Nu beschikbaar: Apple Pay!.*$/m',
        '/^.*Dit product valt onder het depositogarantiestelsel.*$/m',
        '/^.*'.self::MIN_DUE_PARAGRAPH.'.*$/m',
        '/^.*Uw betalingen aan International Card Services BV.*$/m',
    ];

    // The body paragraph naming the minimum payment and the day it is expected.
    // Stripped from the transaction region as noise and read for that day, so
    // the two passes cannot key on different spellings of the same line.
    public const string MIN_DUE_PARAGRAPH = 'Het minimaal te betalen bedrag ad';

    public const string FX_LINE_ANCHOR = 'Wisselkoers ';

    public const string CARD_LAST4_LINE_PREFIX = 'Uw Card met als laatste vier cijfers ';

    public const string SUMMARY_OPENING = 'Vorig openstaand saldo';

    public const string SUMMARY_RECEIVED = 'Totaal ontvangen betalingen';

    public const string SUMMARY_CHARGES = 'Totaal nieuwe uitgaven';

    public const string SUMMARY_CLOSING = 'Nieuw openstaand saldo';

    public const string SUMMARY_CREDIT_LIMIT = 'Bestedingslimiet';

    public const string SUMMARY_MIN_DUE = 'Minimaal te betalen bedrag';
}
