<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Enums;

// The countries a user can pick a tax-deduction corpus for (ISO 3166 alpha-2,
// lowercase). The same closed set gated the settings validator and the corpus
// loader by hand; it lives here once now.
/**
 * @link ../../../../.docs/features/tax/architecture.md
 */
enum TaxCountry: string
{
    case Nl = 'nl';

    case De = 'de';

    case Be = 'be';

    case Fr = 'fr';

    case Gb = 'gb';

    case Us = 'us';
}
