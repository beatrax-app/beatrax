<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Brīdinājumu jutīgums',
    'sensitivity_help' => 'Cik viegli Beatrax uzskata maksājumu par neparastu šim tirgotājam vai kategorijai, no 1 līdz 100. Augstāks atzīmē vairāk.',

    'min_amount_label' => 'Minimālā maksājuma summa',
    'min_amount_help' => 'Ignorēt novirzes maksājumiem, kas mazāki par šo summu. Glabā centos (:symbol) — 1000 nozīmē :example.',

    'save' => 'Saglabāt noviržu iestatījumus',
    'saved' => 'Saglabāts.',

    'suppression' => [
        'summary' => 'Slāpēšanas noteikumi',
        'empty' => 'Vēl nav neviena slāpēšanas noteikuma. Kad atzīmēsiet maksājumu kā gaidītu, šeit parādīsies noteikums.',
        'remove' => 'Noņemt',
        'remove_aria' => 'Noņemt slāpēšanas noteikumu',
        'removed_toast' => 'Noteikums noņemts',
    ],

    'unknown_merchant' => 'Nezināms tirgotājs',

    'detectors' => [
        'large' => 'Liels maksājums',
        'first_time' => 'Pirmā reize',
        'duplicate' => 'Dublikāts',
    ],

    'errors' => [
        'sensitivity_range' => 'Jutīgumam jābūt no 1 līdz 100.',
        'min_amount_negative' => 'Minimālā maksājuma summa nevar būt negatīva.',
    ],
];
