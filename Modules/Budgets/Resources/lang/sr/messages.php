<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budžeti',
        'subtitle' => 'Rasporedi sve do poslednjeg — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Prethodni period',
        'next_aria' => 'Sledeći period',
    ],

    'ready' => [
        'label' => 'Spremno za raspoređivanje',
        'overassigned' => 'Raspoređeno je više nego što imaš — smanji neku kovertu ili sačekaj nove prihode.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Još ništa nije raspoređeno',
        'copy_hint' => 'Kopiraj plan prošlog meseca ili klikni na ćeliju ispod i počni da raspoređuješ.',
        'first_hint' => 'Klikni na ćeliju ispod i počni da raspoređuješ svoj prvi mesec.',
        'copy_button' => 'Kopiraj prošli mesec',
    ],

    'no_categories' => [
        'heading' => 'Još nema kategorija troškova',
        'body' => 'Dodaj kategoriju troškova i počni da joj raspoređuješ novac.',
    ],

    'table' => [
        'category' => 'Kategorija',
        'assigned' => 'Raspoređeno',
        'carried_in' => 'Preneseno',
        'moved' => 'Premešteno',
        'spent' => 'Potrošeno',
        'available' => 'Dostupno',
        'if_overspent' => 'Ako se prekorači',
        'notify_at' => 'Obavesti pri',
        'actions' => 'Radnje',
    ],

    'badge' => [
        'carries_negative' => 'Prenosi minus',
        'unconverted_aria' => 'Potrošnja u valuti bez dostupnog kursa se ovde ne računa — pogledaj kontrolnu tablu',
        'unconverted_title' => 'Potrošnja bez dostupnog kursa se ovde ne računa — pogledaj kontrolnu tablu',
        'over_budget' => ':count preko budžeta',
    ],

    'row' => [
        'assigned_aria' => 'Raspoređeno za :category',
        'overspend_aria' => 'Ako je :category prekoračena',
        'notify_aria' => 'Obavesti me pri procentu iskorišćenosti za :category',
        'move_money' => 'Premesti novac',
        'move' => 'Premesti',
    ],

    'overspend' => [
        'reduce' => 'Smanji iznos spreman za raspoređivanje sledećeg meseca',
        'carry' => 'Prenesi minus u ovoj koverti',
    ],

    'history' => [
        'show' => 'Prikaži istoriju ↓',
        'hide' => 'Sakrij istoriju ↑',
        'moved_from' => 'Premešteno iz :category',
        'moved_to' => 'Premešteno u :category',
        'moved_unreadable' => 'Premešteno sa :category novijom verzijom Beatraxa',
        'undo' => 'Opozovi',
    ],

    'phone' => [
        'spent' => 'Potrošeno :amount',
        'carried_in' => 'Preneseno :amount',
        'moved' => 'Premešteno :amount',
        'available' => 'Dostupno :amount',
        'notify_at' => 'Obavesti pri',
    ],

    'modal' => [
        'move_from' => 'Premesti iz :name',
        'move_from_fallback' => 'koverta',
        'move_to' => 'Premesti u',
        'no_other' => 'Nema drugih koverti',
        'select' => 'Izaberi kovertu',
        'amount' => 'Iznos',
        'available_in' => 'Dostupno u :name: :amount',
        'note' => 'Beleška (opciono)',
        'note_placeholder' => 'npr. Pokrivanje prekoračenja za restorane',
        'cancel' => 'Otkaži',
        'move_funds' => 'Premesti sredstva',
    ],

    'glance' => [
        'see_all' => 'Prikaži sve →',
    ],

    'notices' => [
        'invalid_amount' => 'Unesi ispravan iznos.',
        'threshold_range' => 'Unesi ceo broj između 1 i 200.',
        'copied_last_month' => 'Plan prošlog meseca je kopiran.',
        'choose_envelope' => 'Izaberi kovertu u koju ćeš premestiti novac.',
        'amount_positive' => 'Unesi iznos veći od nule.',
        'move_failed' => 'Premeštanje nije uspelo — pokušaj ponovo.',
        'money_moved' => 'Novac je premešten.',
        'move_undone' => 'Premeštanje je opozvano.',
    ],

    'errors' => [
        'assigned_negative' => 'Raspoređeni iznos ne može biti negativan.',
        'invalid_overspend_mode' => 'Neispravan režim obrade prekoračenja.',
        'threshold_range' => 'Prag obaveštenja mora biti između 1 i 200.',
        'same_envelope' => 'Izvorna i odredišna koverta moraju biti različite.',
        'non_positive_amount' => 'Neispravan ili nepozitivan iznos.',
        'category_not_found' => 'Kategorija nije pronađena ili joj korisnik nema pristup.',
    ],
];
