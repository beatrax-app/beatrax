<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Typ kontrahenta: :type',
        'merchant' => 'Sprzedawca',
        'personal' => 'Osoba prywatna',
        'bank' => 'Bank',
        'government' => 'Instytucja publiczna',
        'self' => 'Własne',
        'unknown' => 'Nieznany',
    ],

    'filter_chips' => [
        'aria' => 'Filtruj według typu',
        'all' => 'Wszystkie',
        'merchant' => 'Sprzedawcy',
        'personal' => 'Osoby prywatne',
        'bank' => 'Banki',
        'government' => 'Instytucje publiczne',
        'self' => 'Własne',
        'unknown' => 'Nieznane',
    ],

    'default_name' => [
        'bank_fee' => 'Opłata bankowa',
    ],

    'cp_card' => [
        'aria' => 'Kontrahent: :name',
        'recent_aria' => 'Ostatnia aktywność',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Łańcuch finansowania: ',
        'join' => ' do ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN ukryty — kliknij Pokaż IBAN, aby go wyświetlić',
        'show' => 'Pokaż IBAN',
        'hide' => 'Ukryj IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Informacja o prywatności kontaktu osobistego',
        'body' => '🔒 To jest kontakt osobisty. IBAN i dane osobowe są domyślnie ukryte i nigdy nie trafiają do eksportów.',
    ],

    'self_stub' => [
        'aria' => 'Nie jest to prawdziwy kontrahent',
        'heading' => 'To nie jest tak naprawdę kontrahent',

        'body_rest_html' => ' pojawia się tutaj, ponieważ w transakcjach występuje jako odcinek finansujący między kontami. To jednak <strong>Twoje własne konto</strong>, a nie ktoś, z kim zawierasz transakcje.',
        'body2' => 'Otwórz widok konta, aby zobaczyć saldo, wyciągi i pełną historię transakcji.',
        'open_cta' => 'Otwórz widok konta :name →',
        'hide_cta' => 'Ukryj z tej listy',
        'recent_legs' => 'Ostatnie odcinki między kontami',
    ],
];
