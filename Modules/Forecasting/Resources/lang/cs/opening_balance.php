<?php

declare(strict_types=1);

return [
    'help_paypal' => 'Exporty z PayPalu neobsahují řádky se zůstatkem, nastav ho proto ručně.',
    'help_default' => 'Přepiš to jen tehdy, když víš, že se aktuální skutečný zůstatek liší od toho, který Beatrax spočítá.',

    'legend' => 'Počáteční zůstatek prognózy — :name',
    'opening_label' => 'Počáteční zůstatek',
    'opening_placeholder' => 'např. :amount',
    'as_of_label' => 'Počáteční zůstatek k datu',
    'as_of_help' => 'Datum, ke kterému částka výše platí.',

    'divergence' => 'Je to o víc než :threshold mimo zůstatek, který Beatrax počítá z tvých importovaných transakcí. Určitě?',
    'computed_is' => 'Beatrax počítá :amount.',
    'use_beatrax' => 'Použít číslo z Beatraxu',
    'use_mine' => 'Použít moje číslo',

    'save' => 'Uložit počáteční zůstatek',
    'remove' => 'Odebrat počáteční zůstatek',
    'saved' => 'Uloženo.',
    'removed' => 'Odebráno.',

    'toast' => [
        'updated' => 'Počáteční zůstatek aktualizován.',
        'removed' => 'Počáteční zůstatek odebrán.',
    ],

    'errors' => [
        'invalid_number' => 'Počáteční zůstatek musí být platné číslo.',
        'date_required' => 'Vyber datum, ke kterému tento počáteční zůstatek platí.',
        'date_invalid' => 'Datum počátečního zůstatku musí být platné datum ISO (YYYY-MM-DD).',
        'date_future' => 'Datum počátečního zůstatku nemůže být v budoucnosti.',
    ],
];
