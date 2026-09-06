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
        'account_maintenance' => 'Opłata za prowadzenie rachunku',
        'monthly_fee' => 'Opłata miesięczna',
        'quarterly_fee' => 'Opłata kwartalna',
        'annual_fee' => 'Opłata roczna',
        'card_fee' => 'Opłata za kartę',
        'transaction_fee' => 'Opłata transakcyjna',
        'transfer_fee' => 'Opłata za przelew',
        'withdrawal_fee' => 'Opłata za wypłatę',
        'transaction_levy' => 'Podatek od transakcji',
        'foreign_transaction_fee' => 'Opłata za przewalutowanie',
        'commission' => 'Prowizja',
        'debit_interest' => 'Odsetki debetowe',
        'overdraft' => 'Opłata za debet',
        'overdraft_interest' => 'Odsetki od debetu',
        'insufficient_funds' => 'Opłata za brak środków',
        'penalty_fee' => 'Opłata karna',
        'loan_arrangement_fee' => 'Prowizja za udzielenie kredytu',
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
        // i18n-review: pl · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN ukryty — dotknij Pokaż IBAN, aby go wyświetlić',
        'show' => 'Pokaż IBAN',
        'hide' => 'Ukryj IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Informacja o prywatności kontaktu osobistego',
        'body' => '🔒 To jest kontakt osobisty. IBAN pozostaje ukryty, dopóki go nie wyświetlisz, i nie trafia do eksportów. Nazwa tego kontaktu nadal pojawia się wszędzie tam, gdzie pojawiają się jego transakcje.',
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
