<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Sensibilità degli avvisi',
    'sensitivity_help' => "Segnala gli addebiti che superano di oltre il :percent% la tua spesa tipica per quell'esercente o categoria.",

    'min_amount_label' => 'Importo minimo di addebito',
    'min_amount_help' => 'Ignora le anomalie sugli addebiti inferiori a questo importo. Memorizzato in centesimi (:symbol) — 1000 corrisponde a :example.',

    'save' => 'Salva impostazioni anomalie',
    'saved' => 'Salvato.',

    'suppression' => [
        'summary' => 'Regole di esclusione',
        'empty' => 'Ancora nessuna regola di esclusione. Quando segni un addebito come previsto, qui appare una regola.',
        'remove' => 'Rimuovi',
        'remove_aria' => 'Rimuovi la regola di esclusione',
        'removed_toast' => 'Regola rimossa',
    ],

    'unknown_merchant' => 'Esercente sconosciuto',

    'detectors' => [
        'large' => 'Addebito elevato',
        'first_time' => 'Prima volta',
        'duplicate' => 'Duplicato',
    ],

    'errors' => [
        'sensitivity_range' => 'La sensibilità deve essere compresa tra 1 e 100.',
        'min_amount_negative' => "L'importo minimo di addebito non può essere negativo.",
    ],
];
