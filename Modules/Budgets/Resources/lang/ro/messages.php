<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Bugete',
        'subtitle' => 'Alocă tot — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Perioada anterioară',
        'next_aria' => 'Perioada următoare',
    ],

    'ready' => [
        'label' => 'Disponibil de alocat',
        'overassigned' => 'Ai alocat mai mult decât ai — redu un plic sau așteaptă venituri suplimentare.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Nimic alocat încă',
        'copy_hint' => 'Copiază planul de luna trecută sau dă clic într-o celulă de mai jos pentru a începe alocarea.',
        'first_hint' => 'Dă clic într-o celulă de mai jos pentru a începe alocarea primei luni.',
        'copy_button' => 'Copiază luna trecută',
    ],

    'no_categories' => [
        'heading' => 'Nicio categorie de cheltuieli',
        'body' => 'Adaugă o categorie de cheltuieli pentru a începe să aloci bani.',
    ],

    'table' => [
        'category' => 'Categorie',
        'assigned' => 'Alocat',
        'spent' => 'Cheltuit',
        'available' => 'Disponibil',
        'if_overspent' => 'Dacă se depășește',
        'notify_at' => 'Notifică la',
        'actions' => 'Acțiuni',
    ],

    'badge' => [
        'carries_negative' => 'Reportează negativul',
        'unconverted_aria' => 'Cheltuielile într-o monedă fără curs disponibil nu sunt numărate aici — vezi panoul',
        'unconverted_title' => 'Cheltuielile fără curs disponibil nu sunt numărate aici — vezi panoul',
        'over_budget' => ':count peste buget',
    ],

    'row' => [
        'assigned_aria' => 'Alocat pentru :category',
        'overspend_aria' => 'Dacă :category este depășită',
        'notify_aria' => 'Notifică-mă la procentul folosit pentru :category',
        'move_money' => 'Mută bani',
        'move' => 'Mută',
    ],

    'overspend' => [
        'reduce' => 'Redu suma disponibilă de alocat luna viitoare',
        'carry' => 'Reportează negativul în acest plic',
    ],

    'history' => [
        'show' => 'Arată istoricul ↓',
        'hide' => 'Ascunde istoricul ↑',
        'moved_from' => 'Mutat din :category',
        'moved_to' => 'Mutat în :category',
        'undo' => 'Anulează acțiunea',
    ],

    'phone' => [
        'spent' => 'Cheltuit :amount',
        'available' => 'Disponibil :amount',
        'notify_at' => 'Notifică la',
    ],

    'modal' => [
        'move_from' => 'Mută din :name',
        'move_from_fallback' => 'plic',
        'move_to' => 'Mută în',
        'no_other' => 'Nu există alte plicuri',
        'select' => 'Alege un plic',
        'amount' => 'Sumă',
        'available_in' => 'Disponibil în :name: :amount',
        'note' => 'Notă (opțional)',
        'note_placeholder' => 'ex. Acoperă depășirea la restaurante',
        'cancel' => 'Anulează',
        'move_funds' => 'Mută fonduri',
    ],

    'glance' => [
        'see_all' => 'Vezi toate →',
    ],

    'notices' => [
        'invalid_amount' => 'Introdu o sumă validă.',
        'threshold_range' => 'Introdu un număr întreg între 1 și 200.',
        'copied_last_month' => 'Planul de luna trecută a fost copiat.',
        'choose_envelope' => 'Alege un plic în care să muți banii.',
        'amount_positive' => 'Introdu o sumă mai mare decât zero.',
        'move_failed' => 'Mutarea nu a putut fi finalizată — încearcă din nou.',
        'money_moved' => 'Banii au fost mutați.',
        'move_undone' => 'Mutarea a fost anulată.',
    ],

    'errors' => [
        'assigned_negative' => 'Suma alocată nu poate fi negativă.',
        'invalid_overspend_mode' => 'Mod de depășire invalid.',
        'threshold_range' => 'Pragul de notificare trebuie să fie între 1 și 200.',
        'same_envelope' => 'Plicul sursă și cel destinație trebuie să fie diferite.',
        'non_positive_amount' => 'Sumă invalidă sau nepozitivă.',
        'category_not_found' => 'Categoria nu a fost găsită sau nu este accesibilă utilizatorului.',
    ],
];
