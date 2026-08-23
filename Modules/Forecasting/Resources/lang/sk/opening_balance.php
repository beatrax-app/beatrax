<?php

declare(strict_types=1);

return [
    'help_paypal' => 'Exporty z PayPalu neobsahujú riadky so zostatkom, preto ho nastav ručne.',
    'help_asn' => 'Automaticky ukotvené podľa tvojho posledného výpisu z účtu. Zmeň to len vtedy, ak vieš, že skutočný zostatok je iný.',
    'help_default' => 'Zmeň to len vtedy, ak vieš, že aktuálny skutočný zostatok sa líši od toho, ktorý vypočíta Beatrax.',

    'legend' => 'Počiatočný zostatok prognózy — :name',
    'opening_label' => 'Počiatočný zostatok',
    'opening_placeholder' => 'napr. 1.250,00',
    'as_of_label' => 'Počiatočný zostatok k dátumu',
    'as_of_help' => 'Dátum, ku ktorému uvedená suma platí.',

    'divergence' => 'Toto je o viac než 500 € vedľa zostatku, ktorý Beatrax vypočíta z tvojich importovaných transakcií. Naozaj?',
    'use_beatrax' => 'Použiť číslo z Beatraxu',
    'use_mine' => 'Použiť moje číslo',

    'save' => 'Uložiť počiatočný zostatok',
    'remove' => 'Odstrániť počiatočný zostatok',
    'saved' => 'Uložené.',
    'removed' => 'Odstránené.',

    'toast' => [
        'updated' => 'Počiatočný zostatok aktualizovaný.',
        'removed' => 'Počiatočný zostatok odstránený.',
    ],

    'errors' => [
        'invalid_number' => 'Počiatočný zostatok musí byť platné číslo.',
        'date_required' => 'Vyber dátum, ku ktorému tento počiatočný zostatok platí.',
        'date_invalid' => 'Dátum počiatočného zostatku musí byť platný dátum ISO (YYYY-MM-DD).',
        'date_future' => 'Dátum počiatočného zostatku nemôže byť v budúcnosti.',
    ],
];
