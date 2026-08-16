<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Sensibilitatea alertelor',
    'sensitivity_help' => 'Semnalează plățile care depășesc cu peste :percent% cheltuiala ta obișnuită la acel comerciant sau la acea categorie.',

    'min_amount_label' => 'Sumă minimă a plății',
    'min_amount_help' => 'Ignoră anomaliile pentru plățile sub această sumă. Stocată în cenți (€) — 1000 înseamnă 10,00 €.',

    'save' => 'Salvează setările pentru anomalii',
    'saved' => 'Salvat.',

    'suppression' => [
        'summary' => 'Reguli de suprimare',
        'empty' => 'Nicio regulă de suprimare deocamdată. Când marchezi o plată drept așteptată, aici apare o regulă.',
        'remove' => 'Elimină',
        'remove_aria' => 'Elimină regula de suprimare',
        'removed_toast' => 'Regulă eliminată',
    ],

    'unknown_merchant' => 'Comerciant necunoscut',

    'detectors' => [
        'large' => 'Plată mare',
        'first_time' => 'Prima dată',
        'duplicate' => 'Duplicat',
    ],

    'errors' => [
        'sensitivity_range' => 'Sensibilitatea trebuie să fie între 1 și 100.',
        'min_amount_negative' => 'Suma minimă a plății nu poate fi negativă.',
    ],
];
