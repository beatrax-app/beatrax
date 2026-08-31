<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Rozpočty',
        'subtitle' => 'Přiděl všechno do posledního — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Předchozí období',
        'next_aria' => 'Další období',
    ],

    'ready' => [
        'label' => 'K přidělení',
        'overassigned' => 'Přiděleno je víc, než máš — sniž některou obálku nebo počkej na další příjem.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Zatím nic nepřiděleno',
        'copy_hint' => 'Zkopíruj plán z minulého měsíce nebo klikni do buňky níž a začni přidělovat.',
        'first_hint' => 'Klikni do buňky níž a začni přidělovat svůj první měsíc.',
        'copy_button' => 'Kopírovat minulý měsíc',
    ],

    'no_categories' => [
        'heading' => 'Zatím žádné výdajové kategorie',
        'body' => 'Přidej výdajovou kategorii a začni jí přidělovat peníze.',
    ],

    'table' => [
        'category' => 'Kategorie',
        'assigned' => 'Přiděleno',
        'carried_in' => 'Převedeno',
        'moved' => 'Přesunuto',
        'spent' => 'Utraceno',
        'available' => 'Dostupné',
        'if_overspent' => 'Při přečerpání',
        'notify_at' => 'Upozornit při',
        'actions' => 'Akce',
    ],

    'badge' => [
        'carries_negative' => 'Přenáší minus',
        'unconverted_aria' => 'Výdaje v měně bez dostupného kurzu se sem nezapočítávají — najdeš je v Přehledu',
        'unconverted_title' => 'Výdaje bez dostupného kurzu se sem nezapočítávají — najdeš je v Přehledu',
        'over_budget' => ':count nad rozpočet',
    ],

    'row' => [
        'assigned_aria' => 'Přiděleno pro kategorii: :category',
        'overspend_aria' => 'Při přečerpání — kategorie: :category',
        'notify_aria' => 'Upozornit mě při procentu využití — kategorie: :category',
        'move_money' => 'Přesunout peníze',
        'move' => 'Přesunout',
    ],

    'overspend' => [
        'reduce' => 'Snížit částku k přidělení pro příští měsíc',
        'carry' => 'Přenést minus v této obálce',
    ],

    'history' => [
        'show' => 'Zobrazit historii ↓',
        'hide' => 'Skrýt historii ↑',
        'moved_from' => 'Přesunuto z: :category',
        'moved_to' => 'Přesunuto do: :category',
        'moved_unreadable' => 'Přesunuto s kategorií :category novější verzí aplikace Beatrax',
        'undo' => 'Vrátit zpět',
    ],

    'phone' => [
        'spent' => 'Utraceno :amount',
        'carried_in' => 'Převedeno :amount',
        'moved' => 'Přesunuto :amount',
        'available' => 'Dostupné :amount',
        'notify_at' => 'Upozornit při',
    ],

    'modal' => [
        'move_from' => 'Přesun z: :name',
        'move_from_fallback' => 'obálka',
        'move_to' => 'Přesunout do',
        'no_other' => 'Žádné jiné obálky',
        'select' => 'Vyber obálku',
        'amount' => 'Částka',
        'available_in' => 'Dostupné (:name): :amount',
        'note' => 'Poznámka (volitelné)',
        'note_placeholder' => 'např. Pokrytí přečerpání za stravování',
        'cancel' => 'Zrušit',
        'move_funds' => 'Přesunout prostředky',
    ],

    'glance' => [
        'see_all' => 'Zobrazit vše →',
    ],

    'notices' => [
        'invalid_amount' => 'Zadej platnou částku.',
        'threshold_range' => 'Zadej celé číslo mezi 1 a 200.',
        'copied_last_month' => 'Plán z minulého měsíce zkopírován.',
        'choose_envelope' => 'Vyber obálku, do které peníze přesunout.',
        'amount_positive' => 'Zadej částku větší než nula.',
        'move_failed' => 'Přesun se nepodařilo dokončit — zkus to prosím znovu.',
        'money_moved' => 'Peníze přesunuty.',
        'move_undone' => 'Přesun vrácen zpět.',
    ],

    'errors' => [
        'assigned_negative' => 'Přidělená částka nemůže být záporná.',
        'invalid_overspend_mode' => 'Neplatný režim přečerpání.',
        'threshold_range' => 'Práh upozornění musí být mezi 1 a 200.',
        'same_envelope' => 'Zdrojová a cílová obálka se musí lišit.',
        'non_positive_amount' => 'Neplatná nebo nekladná částka.',
        'category_not_found' => 'Kategorie nenalezena nebo pro uživatele nepřístupná.',
    ],
];
