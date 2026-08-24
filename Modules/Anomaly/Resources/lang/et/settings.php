<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Hoiatuse tundlikkus',
    'sensitivity_help' => 'Märgista maksed, mis ületavad selle kaupmehe või kategooria tavapärast kulu rohkem kui :percent% võrra.',

    'min_amount_label' => 'Makse minimaalne summa',
    'min_amount_help' => 'Eira kõrvalekaldeid maksetel, mis jäävad sellest summast väiksemaks. Salvestatakse sentides (:symbol) — 1000 tähendab :example.',

    'save' => 'Salvesta kõrvalekallete seaded',
    'saved' => 'Salvestatud.',

    'suppression' => [
        'summary' => 'Summutusreeglid',
        'empty' => 'Summutusreegleid veel pole. Kui märgid makse oodatuks, ilmub reegel siia.',
        'remove' => 'Eemalda',
        'remove_aria' => 'Eemalda summutusreegel',
        'removed_toast' => 'Reegel eemaldatud',
    ],

    'unknown_merchant' => 'Tundmatu kaupmees',

    'detectors' => [
        'large' => 'Suur makse',
        'first_time' => 'Esmakordne',
        'duplicate' => 'Duplikaat',
    ],

    'errors' => [
        'sensitivity_range' => 'Tundlikkus peab olema vahemikus 1 kuni 100.',
        'min_amount_negative' => 'Makse minimaalne summa ei saa olla negatiivne.',
    ],
];
