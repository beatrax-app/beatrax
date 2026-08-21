<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Enums;

// The countries a user can pick a tax-deduction corpus for (ISO 3166 alpha-2,
// lowercase). The same closed set gated the settings validator and the corpus
// loader by hand; it lives here once now.
enum TaxCountry: string
{
    case At = 'at';

    case Be = 'be';

    case Bg = 'bg';

    case Ca = 'ca';

    case Ch = 'ch';

    case Cy = 'cy';

    case Cz = 'cz';

    case De = 'de';

    case Dk = 'dk';

    case Ee = 'ee';

    case Es = 'es';

    case Fi = 'fi';

    case Fr = 'fr';

    case Gb = 'gb';

    case Gr = 'gr';

    case Hr = 'hr';

    case Hu = 'hu';

    case Ie = 'ie';

    case Is = 'is';

    case It = 'it';

    case Lt = 'lt';

    case Lu = 'lu';

    case Lv = 'lv';

    case Mt = 'mt';

    case Nl = 'nl';

    case No = 'no';

    case Pl = 'pl';

    case Pt = 'pt';

    case Ro = 'ro';

    case Se = 'se';

    case Si = 'si';

    case Sk = 'sk';

    case Us = 'us';
}
