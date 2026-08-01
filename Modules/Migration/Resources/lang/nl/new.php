<?php

declare(strict_types=1);

return [
    'page_title' => 'Importeren uit YNAB / Actual',

    'eyebrow' => 'Migraties',
    'heading' => 'Importeren uit YNAB / Actual',
    'intro' => 'Breng je categorieboom, budgetgeschiedenis en transacties over vanuit YNAB4, new YNAB of Actual Budget. Er wordt niets naar je grootboek geschreven totdat je het bekijkt en bevestigt.',
    'reconcile_context' => 'Bezig met controleren op updates ten opzichte van je laatste :product-import.',

    'source_label' => 'Bron',
    'file_label' => 'Bestand',
    'parse_button' => 'Export verwerken',

    'hints' => [
        'ynab4' => 'Exporteer je volledige budget als ZIP-bestand via het menu File → Export in YNAB4.',
        'nynab' => 'Exporteer je budget uit nYNAB via File → Export Budget en zip vervolgens de geëxporteerde CSV-bestanden.',
        'actual' => 'Exporteer je budget als ZIP-bestand via Settings → Export data in Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Dit lijkt geen YNAB4-, nYNAB- of Actual-export te zijn die we kunnen lezen. Controleer het bestand en probeer het opnieuw.',
        'file_too_large' => 'Dat bestand is te groot voor een migratie-export.',
    ],
];
