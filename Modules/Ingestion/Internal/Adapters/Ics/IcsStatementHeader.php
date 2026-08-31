<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Carbon\CarbonImmutable;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;

// The facts an ICS statement states about itself rather than about a
// transaction: which card, which day it was cut, which day it asks to be paid,
// and its sequence number. Its own collaborator because the adapter around it
// reads rows, and these four read the letterhead.
final readonly class IcsStatementHeader
{
    private const string FULL_MONTH_ALTERNATION =
        'januari|februari|maart|april|mei|juni|juli|augustus|september|oktober|november|december';

    public function __construct(
        private IcsDateParser $dates,
    ) {}

    public function cardLast4(string $text): ?string
    {
        $pattern = '/'.preg_quote(IcsPdfExtractionMap::CARD_LAST4_LINE_PREFIX, '/').'(\S{4})/';

        if (preg_match($pattern, $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public function statementDate(string $text): ?CarbonImmutable
    {
        if (
            preg_match(
                '/(\d{1,2})\s+('.self::FULL_MONTH_ALTERNATION.')\s+(\d{4})/i',
                $text,
                $m,
            ) === 1
        ) {
            return $this->safeParseDutchDate($m[1], $m[2], $m[3]);
        }

        return null;
    }

    // The issuer states one day, unambiguously, in the body paragraph naming
    // the minimum payment. Derived instead, as period_end plus a constant, it
    // came out nineteen days early on the committed statement and the payment
    // made on the day asked for matched nothing.
    /**
     * @link ../../../../../.docs/conventions/invariants-from-shipped-failures.md#a-tolerance-calibrated-on-a-synthesised-fixture-while-a-real-one-disagrees
     */
    public function paymentDueDate(string $text): ?CarbonImmutable
    {
        $pattern = '/'.preg_quote(IcsPdfExtractionMap::MIN_DUE_PARAGRAPH, '/')
            .'[^\n]*?(\d{1,2})\s+('.self::FULL_MONTH_ALTERNATION.')\s+(\d{4})/iu';

        if (preg_match($pattern, $text, $m) === 1) {
            return $this->safeParseDutchDate($m[1], $m[2], $m[3]);
        }

        return null;
    }

    public function statementNumber(string $text): ?string
    {
        if (
            preg_match(
                '/Volgnummer\s+Bladnummer\s*\n[^\n]*?(\d+)\s+\d+\s+van\s+\d+/m',
                $text,
                $m,
            ) === 1
        ) {
            return $m[1];
        }

        return null;
    }

    private function safeParseDutchDate(string $day, string $month, string $year): ?CarbonImmutable
    {
        try {
            return $this->dates->parse(sprintf('%s %s %s', $day, $month, $year));
        } catch (InvalidAmountException) {
            return null;
        }
    }
}
