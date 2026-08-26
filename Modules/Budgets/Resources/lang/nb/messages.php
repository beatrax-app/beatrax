<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budsjetter',
        'subtitle' => 'Fordel alt — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Forrige periode',
        'next_aria' => 'Neste periode',
    ],

    'ready' => [
        'label' => 'Klar til fordeling',
        'overassigned' => 'Du har fordelt mer enn du har — reduser en konvolutt eller vent på mer inntekt.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Ingenting er fordelt ennå',
        'copy_hint' => 'Kopier forrige måneds plan, eller klikk i en celle nedenfor for å begynne å fordele.',
        'first_hint' => 'Klikk i en celle nedenfor for å begynne å fordele den første måneden din.',
        'copy_button' => 'Kopier forrige måned',
    ],

    'no_categories' => [
        'heading' => 'Ingen utgiftskategorier ennå',
        'body' => 'Legg til en utgiftskategori for å begynne å fordele penger til den.',
    ],

    'table' => [
        'category' => 'Kategori',
        'assigned' => 'Fordelt',
        'spent' => 'Brukt',
        'available' => 'Tilgjengelig',
        'if_overspent' => 'Ved overforbruk',
        'notify_at' => 'Varsle ved',
        'actions' => 'Handlinger',
    ],

    'badge' => [
        'carries_negative' => 'Overfører minus',
        'unconverted_aria' => 'Forbruk i en valuta uten tilgjengelig kurs telles ikke med her — se oversikten',
        'unconverted_title' => 'Forbruk uten tilgjengelig kurs telles ikke med her — se oversikten',
        'over_budget' => ':count over budsjett',
    ],

    'row' => [
        'assigned_aria' => 'Fordelt for :category',
        'overspend_aria' => 'Hvis :category overskrides',
        'notify_aria' => 'Varsle meg ved prosent brukt for :category',
        'move_money' => 'Flytt penger',
        'move' => 'Flytt',
    ],

    'overspend' => [
        'reduce' => 'Reduser neste måneds klar til fordeling',
        'carry' => 'Overfør minuset i denne konvolutten',
    ],

    'history' => [
        'show' => 'Vis historikk ↓',
        'hide' => 'Skjul historikk ↑',
        'moved_from' => 'Flyttet fra :category',
        'moved_to' => 'Flyttet til :category',
        'undo' => 'Angre',
    ],

    'phone' => [
        'spent' => 'Brukt :amount',
        'available' => 'Tilgjengelig :amount',
        'notify_at' => 'Varsle ved',
    ],

    'modal' => [
        'move_from' => 'Flytt fra :name',
        'move_from_fallback' => 'konvolutt',
        'move_to' => 'Flytt til',
        'no_other' => 'Ingen andre konvolutter',
        'select' => 'Velg en konvolutt',
        'amount' => 'Beløp',
        'available_in' => 'Tilgjengelig i :name: :amount',
        'note' => 'Notat (valgfritt)',
        'note_placeholder' => 'f.eks. Dekker overforbruk på restaurant',
        'cancel' => 'Avbryt',
        'move_funds' => 'Flytt midler',
    ],

    'glance' => [
        'see_all' => 'Se alle →',
    ],

    'notices' => [
        'invalid_amount' => 'Skriv inn et gyldig beløp.',
        'threshold_range' => 'Skriv inn et heltall mellom 1 og 200.',
        'copied_last_month' => 'Forrige måneds plan er kopiert.',
        'choose_envelope' => 'Velg en konvolutt pengene skal flyttes til.',
        'amount_positive' => 'Skriv inn et beløp større enn null.',
        'move_failed' => 'Kunne ikke fullføre flyttingen — prøv igjen.',
        'money_moved' => 'Pengene er flyttet.',
        'move_undone' => 'Flyttingen er angret.',
    ],

    'errors' => [
        'assigned_negative' => 'Fordelt beløp kan ikke være negativt.',
        'invalid_overspend_mode' => 'Ugyldig modus for overforbruk.',
        'threshold_range' => 'Varslingsterskelen må være mellom 1 og 200.',
        'same_envelope' => 'Kilde- og målkonvolutt må være forskjellige.',
        'non_positive_amount' => 'Ugyldig eller ikke-positivt beløp.',
        'category_not_found' => 'Kategorien ble ikke funnet eller er ikke tilgjengelig for brukeren.',
    ],
];
