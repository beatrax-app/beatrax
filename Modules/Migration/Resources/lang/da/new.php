<?php

declare(strict_types=1);

return [
    'page_title' => 'Importér fra YNAB / Actual',

    'eyebrow' => 'Migreringer',
    'heading' => 'Importér fra YNAB / Actual',
    'intro' => 'Tag dit kategoritræ, din budgethistorik og dine transaktioner med fra YNAB4, nye YNAB eller Actual Budget. Der skrives intet til dine transaktioner, før du har gennemgået og bekræftet.',
    'reconcile_context' => 'Søger efter opdateringer i forhold til din seneste import fra :product.',

    'source_label' => 'Kilde',
    'file_label' => 'Fil',
    'parse_button' => 'Indlæs eksporten',

    'hints' => [
        'ynab4' => 'Eksportér hele dit budget som en ZIP-fil fra menuen File → Export i YNAB4.',
        'nynab' => 'Eksportér dit budget fra nYNAB via File → Export Budget, og pak derefter de eksporterede CSV-filer i en ZIP-fil.',
        'actual' => 'Eksportér dit budget som en ZIP-fil fra Settings → Export data i Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Dette ligner ikke en eksport fra YNAB4, nYNAB eller Actual, som vi kan læse. Kontrollér filen, og prøv igen.',
        'file_too_large' => 'Filen er for stor til en migreringseksport.',
    ],
];
