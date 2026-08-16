<?php

declare(strict_types=1);

return [
    'help_paypal' => 'Izvozi iz PayPala ne vsebujejo vrstic s stanjem, zato to nastavi ročno.',
    'help_asn' => 'Samodejno zasidrano po tvojem zadnjem izpisku. Povozi samo, če veš, da se dejansko stanje razlikuje.',
    'help_default' => 'Povozi samo, če veš, da se trenutno dejansko stanje razlikuje od tistega, kar izračuna Beatrax.',

    'legend' => 'Začetno stanje napovedi za :name',
    'opening_label' => 'Začetno stanje',
    'opening_placeholder' => 'npr. 1.250,00',
    'as_of_label' => 'Začetno stanje na dan',
    'as_of_help' => 'Datum, za katerega velja zgornji znesek.',

    'divergence' => 'To za več kot €500 odstopa od stanja, ki ga Beatrax izračuna iz tvojih uvoženih transakcij. Si prepričan?',
    'use_beatrax' => 'Uporabi Beatraxov znesek',
    'use_mine' => 'Uporabi moj znesek',

    'save' => 'Shrani začetno stanje',
    'saved' => 'Shranjeno.',

    'toast' => [
        'updated' => 'Začetno stanje je posodobljeno.',
    ],

    'errors' => [
        'invalid_number' => 'Začetno stanje mora biti veljavno število.',
        'date_required' => 'Izberi datum, na katerega se nanaša to začetno stanje.',
        'date_invalid' => 'Datum začetnega stanja mora biti veljaven datum ISO (YYYY-MM-DD).',
        'date_future' => 'Datum začetnega stanja ne more biti v prihodnosti.',
    ],
];
