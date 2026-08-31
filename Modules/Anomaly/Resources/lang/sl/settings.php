<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Občutljivost opozoril',
    'sensitivity_help' => 'Kako hitro Beatrax označi bremenitev kot nenavadno za tega trgovca ali kategorijo, od 1 do 100. Višje označi več.',

    'min_amount_label' => 'Najmanjši znesek bremenitve',
    'min_amount_help' => 'Prezri anomalije pri bremenitvah pod tem zneskom. Shranjeno v najmanjših enotah (:symbol) — :minor pomeni :example.',

    'save' => 'Shrani nastavitve anomalij',
    'saved' => 'Shranjeno.',

    'suppression' => [
        'summary' => 'Pravila zadušitve',
        'empty' => 'Pravil zadušitve še ni. Ko bremenitev označiš kot pričakovano, se tu pojavi pravilo.',
        'remove' => 'Odstrani',
        'remove_aria' => 'Odstrani pravilo zadušitve',
        'removed_toast' => 'Pravilo je odstranjeno',
    ],

    'unknown_merchant' => 'Neznan trgovec',

    'detectors' => [
        'large' => 'Velika bremenitev',
        'first_time' => 'Prvič',
        'duplicate' => 'Podvojeno',
    ],

    'errors' => [
        'sensitivity_range' => 'Občutljivost mora biti med 1 in 100.',
        'min_amount_negative' => 'Najmanjši znesek bremenitve ne sme biti negativen.',
    ],
];
