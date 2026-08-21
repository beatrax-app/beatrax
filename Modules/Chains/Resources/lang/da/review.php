<?php

declare(strict_types=1);

return [
    'page_title' => 'Gennemgå kæder',
    'heading' => 'Gennemgå kæder',
    'hint' => ':count hint|:count hints',
    'subtitle' => 'Bekræft eller afvis kandidatforbindelser, som kædeløseren ikke kunne bekræfte automatisk.',

    'empty_heading' => 'Intet at gennemgå',
    'empty_body' => 'Hver kædeforbindelse er enten bekræftet eller afvist. Nye kandidater dukker op her, efterhånden som importer kommer ind.',

    'auto_confirm_nudge' => 'Én bekræftelse mere, så bekræftes lignende forbindelser automatisk.',

    'confirm' => 'Bekræft',
    'reject' => 'Afvis',
    'confirm_aria' => 'Bekræft kædeforbindelse :id',
    'reject_aria' => 'Afvis kædeforbindelse :id',
    'show_more' => 'Vis flere',

    'kind' => [
        'paypal_funding' => 'PayPal-finansiering',
        'ics_bulk_settle' => 'Samlet iDEAL-afregning',
    ],

    'errors' => [
        'confirm_hint' => 'Denne kandidat er et hint — åbn det, og tilknyt den matchende transaktion, før du bekræfter.',
        'reject_hint' => 'Denne kandidat er et hint — åbn det, og tilknyt den matchende transaktion, før du afviser.',
    ],
];
