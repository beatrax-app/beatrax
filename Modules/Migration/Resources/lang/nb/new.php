<?php

declare(strict_types=1);

return [
    'page_title' => 'Importer fra YNAB / Actual',

    'eyebrow' => 'Migreringer',
    'heading' => 'Importer fra YNAB / Actual',
    'intro' => 'Ta med deg kategoritreet, budsjetthistorikken og transaksjonene dine fra YNAB4, nye YNAB eller Actual Budget. Ingenting skrives til transaksjonene dine før du har gått gjennom og bekreftet.',
    'reconcile_context' => 'Ser etter oppdateringer mot den siste importen din fra :product.',

    'source_label' => 'Kilde',
    'file_label' => 'Fil',
    'parse_button' => 'Les inn eksporten',

    'hints' => [
        'ynab4' => 'Eksporter hele budsjettet ditt som en ZIP-fil fra menyen File → Export i YNAB4.',
        'nynab' => 'Eksporter budsjettet ditt fra nYNAB via File → Export Budget, og pakk deretter de eksporterte CSV-filene i en ZIP-fil.',
        'actual' => 'Eksporter budsjettet ditt som en ZIP-fil fra Settings → Export data i Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Dette ser ikke ut som en eksport fra YNAB4, nYNAB eller Actual som vi kan lese. Kontroller filen og prøv igjen.',
        'file_too_large' => 'Filen er for stor til en migreringseksport.',
    ],
];
