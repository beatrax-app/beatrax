<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPali ekspordid ei sisalda jäägiridu, seega määra see käsitsi.',
    'help_asn' => 'Ankurdatud automaatselt sinu viimasest kontoväljavõttest. Muuda ainult siis, kui tead, et tegelik jääk erineb.',
    'help_default' => 'Muuda ainult siis, kui tead, et praegune tegelik jääk erineb sellest, mille Beatrax arvutab.',

    'legend' => 'Konto :name prognoosi algjääk',
    'opening_label' => 'Algjääk',
    'opening_placeholder' => 'nt 1250,00',
    'as_of_label' => 'Algjääk seisuga',
    'as_of_help' => 'Kuupäev, mille seisuga ülalolev arv kehtib.',

    'divergence' => 'See erineb rohkem kui 500 € võrra jäägist, mille Beatrax sinu imporditud tehingutest arvutab. Kas oled kindel?',
    'use_beatrax' => 'Kasuta Beatraxi arvu',
    'use_mine' => 'Kasuta minu arvu',

    'save' => 'Salvesta algjääk',
    'saved' => 'Salvestatud.',

    'toast' => [
        'updated' => 'Algjääk on uuendatud.',
    ],

    'errors' => [
        'invalid_number' => 'Algjääk peab olema kehtiv arv.',
        'date_required' => 'Vali kuupäev, mille kohta see algjääk kehtib.',
        'date_invalid' => 'Algjäägi kuupäev peab olema kehtiv ISO-kuupäev (AAAA-KK-PP).',
        'date_future' => 'Algjäägi kuupäev ei saa olla tulevikus.',
    ],
];
