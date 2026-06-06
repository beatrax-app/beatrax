<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

/**
 * Pre-normalises an MT940 counterparty name before it reaches the shared
 * `FingerprintComposer::normalize` step. Strips MT940-specific noise that
 * the shared normaliser does not know about — leading GVC posting codes,
 * leading 4-char transaction-type codes, embedded BIC codes, and the
 * `/REMI/`, `/NAME/`, `/IBAN/`, `/BIC/`, GVC-keyword `/`-markers that
 * SEPA narrative occasionally folds into the name field.
 *
 * Returns a whitespace-collapsed string; case folding and diacritic
 * stripping stay in the shared normaliser so the same logical
 * counterparty resolves to the same canonical key across CSV, CAMT, and
 * MT940 imports.
 */
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
