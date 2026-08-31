<?php

declare(strict_types=1);

return [
    'help_paypal' => 'Exporturile PayPal nu conțin linii de sold, așa că setează manual această valoare.',
    'help_default' => 'Suprascrie doar dacă știi că soldul curent real diferă de cel calculat de Beatrax.',

    'legend' => 'Sold inițial pentru previziune — :name',
    'opening_label' => 'Sold inițial',
    'opening_placeholder' => 'ex. :amount',
    'as_of_label' => 'Sold inițial valabil la data',
    'as_of_help' => 'Data la care cifra de mai sus este corectă.',

    'divergence' => 'Aceasta diferă cu peste :threshold față de soldul calculat de Beatrax din tranzacțiile tale importate. Ești sigur?',
    'computed_is' => 'Beatrax calculează :amount.',
    'use_beatrax' => 'Folosește cifra Beatrax',
    'use_mine' => 'Folosește cifra mea',

    'save' => 'Salvează soldul inițial',
    'remove' => 'Elimină soldul inițial',
    'saved' => 'Salvat.',
    'removed' => 'Eliminat.',

    'toast' => [
        'updated' => 'Sold inițial actualizat.',
        'removed' => 'Sold inițial eliminat.',
    ],

    'errors' => [
        'invalid_number' => 'Soldul inițial trebuie să fie un număr valid.',
        'date_required' => 'Alege data la care se aplică acest sold inițial.',
        'date_invalid' => 'Data soldului inițial trebuie să fie o dată ISO validă (YYYY-MM-DD).',
        'date_future' => 'Data soldului inițial nu poate fi în viitor.',
    ],
];
