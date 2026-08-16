<?php

declare(strict_types=1);

return [
    'page_title' => 'Kontrahenci',
    'heading' => 'Kontrahenci',

    'entities' => 'Podmioty: :count',
    'need_identification' => 'Do zidentyfikowania: :count',

    'search_placeholder' => 'Szukaj po nazwie, aliasie lub numerze IBAN…',
    'search_aria' => 'Szukaj kontrahentów',
    'sort' => 'Sortowanie: suma 12 mies. ↓',

    'view_mode' => 'Tryb widoku',
    'view_cards' => 'Karty',
    'view_list' => 'Lista',

    'filter_aria' => 'Filtruj według typu',
    'chips' => [
        'all' => 'Wszyscy',
        'merchant' => 'Sprzedawcy',
        'personal' => 'Osoby prywatne',
        'bank' => 'Banki',
        'government' => 'Instytucje publiczne',
        'self' => 'Własne',
        'unknown' => 'Nieznani',
    ],

    'empty_heading' => 'Nie ma jeszcze kontrahentów',
    'empty_body' => 'Kontrahenci pojawiają się tutaj automatycznie w miarę importowania transakcji. Zaimportuj wyciąg, aby zacząć.',
    'empty_cta' => 'Zaimportuj wyciąg →',

    'self_routing' => 'Tylko przeksięgowania',
    'self_no_flow' => 'brak wydatków / brak wpływów',
    'self_open' => 'Otwórz konto →',

    'label_this' => 'Oznacz tego kontrahenta',

    'stat_12mo' => '12 mies.',
    'stat_net_received' => 'Netto otrzymane',
    'stat_avg_mo' => 'Śr. / mies.',
    'sparkline_aria' => 'Wykres aktywności z 12 miesięcy',

    'table_name' => 'Nazwa',
    'table_type' => 'Typ',
    'table_12mo' => '12 mies.',
    'table_avg' => 'Śr. / mies.',
];
