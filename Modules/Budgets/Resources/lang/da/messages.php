<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budgetter',
        'subtitle' => 'Fordel det hele — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Forrige periode',
        'next_aria' => 'Næste periode',
    ],

    'ready' => [
        'label' => 'Klar til fordeling',
        'overassigned' => 'Du har fordelt mere, end du har — reducér en kuvert, eller vent på flere indtægter.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Der er endnu ikke fordelt noget',
        'copy_hint' => 'Kopiér sidste måneds plan, eller klik i en celle nedenfor for at begynde at fordele.',
        'first_hint' => 'Klik i en celle nedenfor for at begynde at fordele din første måned.',
        'copy_button' => 'Kopiér sidste måned',
    ],

    'no_categories' => [
        'heading' => 'Ingen udgiftskategorier endnu',
        'body' => 'Tilføj en udgiftskategori for at begynde at fordele penge til den.',
    ],

    'table' => [
        'category' => 'Kategori',
        'assigned' => 'Fordelt',
        'carried_in' => 'Overført',
        'moved' => 'Flyttet',
        'spent' => 'Brugt',
        'available' => 'Til rådighed',
        'if_overspent' => 'Ved overforbrug',
        'notify_at' => 'Notificér ved',
        'actions' => 'Handlinger',
    ],

    'badge' => [
        'carries_negative' => 'Overfører minus',
        'unconverted_aria' => 'Forbrug i en valuta uden tilgængelig kurs tælles ikke med her — se overblikket',
        'unconverted_title' => 'Forbrug uden tilgængelig kurs tælles ikke med her — se overblikket',
        'over_budget' => ':count over budget',
    ],

    'row' => [
        'assigned_aria' => 'Fordelt til :category',
        'overspend_aria' => 'Hvis :category overskrides',
        'notify_aria' => 'Notificér mig ved procent brugt for :category',
        'move_money' => 'Flyt penge',
        'move' => 'Flyt',
    ],

    'overspend' => [
        'reduce' => 'Reducér næste måneds klar til fordeling',
        'carry' => 'Overfør minusset i denne kuvert',
    ],

    'history' => [
        'show' => 'Vis historik ↓',
        'hide' => 'Skjul historik ↑',
        'moved_from' => 'Flyttet fra :category',
        'moved_to' => 'Flyttet til :category',
        'moved_unreadable' => 'Flyttet med :category af en nyere version af Beatrax',
        'undo' => 'Fortryd',
    ],

    'phone' => [
        'spent' => 'Brugt :amount',
        'carried_in' => 'Overført :amount',
        'moved' => 'Flyttet :amount',
        'available' => 'Til rådighed :amount',
        'notify_at' => 'Notificér ved',
    ],

    'modal' => [
        'move_from' => 'Flyt fra :name',
        'move_from_fallback' => 'kuvert',
        'move_to' => 'Flyt til',
        'no_other' => 'Ingen andre kuverter',
        'select' => 'Vælg en kuvert',
        'amount' => 'Beløb',
        'available_in' => 'Til rådighed i :name: :amount',
        'note' => 'Note (valgfrit)',
        'note_placeholder' => 'f.eks. Dækker overforbrug på restaurant',
        'cancel' => 'Annullér',
        'move_funds' => 'Flyt midler',
    ],

    'glance' => [
        'see_all' => 'Se alle →',
    ],

    'notices' => [
        'invalid_amount' => 'Indtast et gyldigt beløb.',
        'threshold_range' => 'Indtast et helt tal mellem 1 og 200.',
        'copied_last_month' => 'Sidste måneds plan er kopieret.',
        'choose_envelope' => 'Vælg en kuvert, pengene skal flyttes til.',
        'amount_positive' => 'Indtast et beløb større end nul.',
        'move_failed' => 'Flytningen kunne ikke gennemføres — prøv igen.',
        'money_moved' => 'Pengene er flyttet.',
        'move_undone' => 'Flytningen er fortrudt.',
    ],

    'errors' => [
        'assigned_negative' => 'Det fordelte beløb kan ikke være negativt.',
        'invalid_overspend_mode' => 'Ugyldig tilstand for overforbrug.',
        'threshold_range' => 'Notifikationstærsklen skal være mellem 1 og 200.',
        'same_envelope' => 'Kilde- og målkuvert skal være forskellige.',
        'non_positive_amount' => 'Ugyldigt eller ikke-positivt beløb.',
        'category_not_found' => 'Kategorien blev ikke fundet eller er ikke tilgængelig for brugeren.',
    ],
];
