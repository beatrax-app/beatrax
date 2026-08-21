<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

final class Mt940CounterpartyCleaner
{
    private const GVC_PREFIX_REGEX = '/^\d{3}\s+/';

    private const TXTYPE_PREFIX_REGEX = '/^(NTRF|NDDT|NMSC|SCHG|NREF|NRTI|NDAS|NCMI|NCMZ)\s+/';

    private const BIC_REGEX = '/\b[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?\b/';

    private const SEPA_MARKER_REGEX = '/\/(REMI|NAME|IBAN|BIC|EREF|MREF|CRED|SVWZ|KREF|PURP|ABWA|MDAT|COAM|OAMT)\//';

    public function clean(string $raw): string
    {
        $s = preg_replace(self::GVC_PREFIX_REGEX, '', $raw);
        $s ??= $raw;
        $s = preg_replace(self::TXTYPE_PREFIX_REGEX, '', $s);
        $s ??= $raw;
        $s = preg_replace(self::BIC_REGEX, '', $s);
        $s ??= $raw;
        $s = preg_replace(self::SEPA_MARKER_REGEX, ' ', $s);
        $s ??= $raw;
        $s = preg_replace('/\s+/', ' ', $s);
        $s ??= $raw;

        return trim($s);
    }
}
