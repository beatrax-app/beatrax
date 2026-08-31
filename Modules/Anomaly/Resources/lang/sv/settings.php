<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Varningskänslighet',
    'sensitivity_help' => 'Hur lätt Beatrax kallar en debitering ovanlig för den handlaren eller kategorin, från 1 till 100. Högre flaggar fler.',

    'min_amount_label' => 'Minsta debiteringsbelopp',
    'min_amount_help' => 'Ignorera anomalier för debiteringar under det här beloppet. Lagras i minsta enheter (:symbol) — :minor betyder :example.',

    'save' => 'Spara anomaliinställningar',
    'saved' => 'Sparat.',

    'suppression' => [
        'summary' => 'Undantagsregler',
        'empty' => 'Inga undantagsregler än. När du markerar en debitering som förväntad visas en regel här.',
        'remove' => 'Ta bort',
        'remove_aria' => 'Ta bort undantagsregeln',
        'removed_toast' => 'Regeln har tagits bort',
    ],

    'unknown_merchant' => 'Okänd handlare',

    'detectors' => [
        'large' => 'Stor debitering',
        'first_time' => 'Första gången',
        'duplicate' => 'Dubblett',
    ],

    'errors' => [
        'sensitivity_range' => 'Känsligheten måste vara mellan 1 och 100.',
        'min_amount_negative' => 'Minsta debiteringsbelopp kan inte vara negativt.',
    ],
];
