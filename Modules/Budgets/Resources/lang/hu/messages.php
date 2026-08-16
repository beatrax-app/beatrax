<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Költségvetések',
        'subtitle' => 'Oszd be minden eurót — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Előző időszak',
        'next_aria' => 'Következő időszak',
    ],

    'ready' => [
        'label' => 'Kiosztható',
        'overassigned' => 'Többet osztottál ki, mint amennyid van — csökkents egy borítékot, vagy várj további bevételre.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Még nincs kiosztás',
        'copy_hint' => 'Másold át az előző havi tervet, vagy kattints egy alábbi cellába a kiosztás megkezdéséhez.',
        'first_hint' => 'Kattints egy alábbi cellába, és oszd be az első hónapodat.',
        'copy_button' => 'Előző hónap másolása',
    ],

    'no_categories' => [
        'heading' => 'Még nincs kiadási kategória',
        'body' => 'Adj hozzá egy kiadási kategóriát, hogy pénzt oszthass rá.',
    ],

    'table' => [
        'category' => 'Kategória',
        'assigned' => 'Kiosztva',
        'spent' => 'Elköltve',
        'available' => 'Elérhető',
        'if_overspent' => 'Túlköltés esetén',
        'notify_at' => 'Értesítés ekkor',
        'actions' => 'Műveletek',
    ],

    'badge' => [
        'carries_negative' => 'Negatívot visz tovább',
        'non_eur_aria' => 'A kategória nem euróban lévő költése itt nem jelenik meg — lásd az irányítópultot',
        'non_eur_title' => 'A nem euróban lévő költés itt nem jelenik meg — lásd az irányítópultot',
        'over_budget' => ':count túllépve',
    ],

    'row' => [
        'assigned_aria' => 'Kiosztva ide: :category',
        'overspend_aria' => 'Ha a(z) :category túl van költve',
        'notify_aria' => 'Értesítés a felhasznált százaléknál ehhez: :category',
        'move_money' => 'Pénz átmozgatása',
        'move' => 'Átmozgatás',
    ],

    'overspend' => [
        'reduce' => 'Csökkentse a jövő havi kiosztható keretet',
        'carry' => 'Vigye tovább a negatívot ebben a borítékban',
    ],

    'history' => [
        'show' => 'Előzmények megjelenítése ↓',
        'hide' => 'Előzmények elrejtése ↑',
        'moved_from' => 'Áthelyezve innen: :category',
        'moved_to' => 'Áthelyezve ide: :category',
        'undo' => 'Visszavonás',
    ],

    'phone' => [
        'spent' => 'Elköltve: :amount',
        'available' => 'Elérhető: :amount',
        'notify_at' => 'Értesítés ekkor',
    ],

    'modal' => [
        'move_from' => 'Átmozgatás innen: :name',
        'move_from_fallback' => 'boríték',
        'move_to' => 'Átmozgatás ide',
        'no_other' => 'Nincs másik boríték',
        'select' => 'Válassz borítékot',
        'amount' => 'Összeg',
        'available_in' => 'Elérhető itt: :name: :amount',
        'note' => 'Megjegyzés (opcionális)',
        'note_placeholder' => 'pl. Étkezési túlköltés fedezése',
        'cancel' => 'Mégse',
        'move_funds' => 'Pénz átmozgatása',
    ],

    'glance' => [
        'see_all' => 'Összes megtekintése →',
    ],

    'notices' => [
        'invalid_amount' => 'Adj meg érvényes összeget.',
        'threshold_range' => 'Adj meg egy egész számot 1 és 200 között.',
        'copied_last_month' => 'Az előző havi terv átmásolva.',
        'choose_envelope' => 'Válaszd ki, melyik borítékba kerüljön a pénz.',
        'amount_positive' => 'Adj meg nullánál nagyobb összeget.',
        'move_failed' => 'Az átmozgatás nem sikerült — próbáld újra.',
        'money_moved' => 'A pénz átmozgatva.',
        'move_undone' => 'Az átmozgatás visszavonva.',
    ],

    'errors' => [
        'assigned_negative' => 'A kiosztott összeg nem lehet negatív.',
        'invalid_overspend_mode' => 'Érvénytelen túlköltési mód.',
        'threshold_range' => 'Az értesítési küszöbnek 1 és 200 között kell lennie.',
        'same_envelope' => 'A forrás- és a célborítéknak különböznie kell.',
        'non_positive_amount' => 'Érvénytelen vagy nem pozitív összeg.',
        'category_not_found' => 'A kategória nem található, vagy a felhasználó nem férhet hozzá.',
    ],
];
