<?php

declare(strict_types=1);

return [
    'heading_named' => 'Kedja för :name',
    'heading' => 'Kedja',

    'unresolved_heading' => 'Kedjan är inte löst ännu',
    'unresolved_body' => 'Kedjelösaren körs fortfarande. Öppna granskningskön eller uppdatera om en stund.',

    'none_heading' => 'Ingen finansieringskedja hittades',
    'none_body' => 'Ingen finansieringskedja har upptäckts för den här transaktionen. Om du väntade dig en, skicka in en kandidat från granskningskön.',

    'none_beyond_leg' => 'Ingen finansieringskedja hittades bortom det här ledet.',

    'covers_charges' => 'Täcker :count ICS-debitering|Täcker :count ICS-debiteringar',
    'no_ics_charges' => 'Inga ICS-debiteringar i den här avräkningen',
    'show_more_fanout' => 'Visa :count till · :shown av :total',

    'confirm' => 'Bekräfta',
    'reject' => 'Avvisa',
    'confirm_aria' => 'Bekräfta kedjelänk :id',
    'reject_aria' => 'Avvisa kedjelänk :id',

    'confidence_aria' => [
        'deterministic' => 'Säkerhet: deterministisk matchning',
        'confirmed' => 'Säkerhet: bekräftad',
        'candidate' => 'Säkerhet: kandidat; behöver granskas',
    ],
];
