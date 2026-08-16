<?php

declare(strict_types=1);

return [
    'page_title' => 'Kasice · Beatrax',
    'heading' => 'Kasice',
    'subtitle' => 'Virtualna podstanja koja se uvijek zbrajaju u stvarno stanje računa.',
    'add_pot' => 'Dodaj kasicu',

    'pot_fallback' => 'kasica',

    'empty' => [
        'heading' => 'Još nema kasica',
        'body' => 'Napravi virtualna podstanja unutar bilo kojeg računa i sredi novac bez stvarnog bankovnog prijenosa.',
        'cta' => 'Dodaj svoju prvu kasicu',
    ],

    'common' => [
        'cancel' => 'Odustani',
        'amount' => 'Iznos',
        'note_optional' => 'Bilješka (neobavezno)',
    ],

    'actions' => [
        'fund' => 'Uplati',
        'move' => 'Premjesti',
        'edit' => 'Uredi',
        'withdraw' => 'Podigni',
        'archive' => 'Arhiviraj',
        'restore' => 'Vrati',
    ],

    'recon' => [
        'over_allocated' => 'Kasice premašuju stvarno stanje za :amount — uravnoteži ih da to popraviš',
        'real_balance' => 'Stvarno stanje:',
        'allocated' => 'Raspoređeno:',
        'unallocated' => 'Neraspoređeno:',
    ],

    'chip' => [
        'goal' => 'Cilj:',
        'goal_name_fallback' => 'Cilj',
        'category_fallback' => 'Kategorija',
    ],

    'coverage' => [
        'spent' => 'potrošeno',
        'in_pot' => 'u kasici',
    ],

    'archive_confirm' => 'Arhivirati ovu kasicu? Stanje od :amount vratit će se u neraspoređeno.',
    'confirm_archive_aria' => 'Potvrdi arhiviranje kasice: :name',
    'more_actions_aria' => 'Više radnji za kasicu: :name',

    'history' => [
        'show' => 'Prikaži povijest ↓',
        'hide' => 'Sakrij povijest ↑',
    ],

    'movement' => [
        'fund' => 'Uplata',
        'withdraw' => 'Isplata',
        'moved_from' => 'Premješteno iz kasice: :name',
        'moved_to' => 'Premješteno u kasicu: :name',
    ],

    'archived' => [
        'toggle' => 'Arhivirane kasice (:count)',
        'badge' => 'Arhivirano',
    ],

    'form' => [
        'create_title' => 'Napravi kasicu',
        'edit_title' => 'Uredi kasicu',
        'create_subtitle' => 'Imenuj virtualno podstanje unutar računa.',
        'edit_subtitle' => 'Ažuriraj naziv ili poveznicu ove kasice.',
        'name' => 'Naziv',
        'name_placeholder' => 'npr. Fond za godišnji',
        'account' => 'Račun',
        'select_account' => 'Odaberi račun',
        'initial_amount' => 'Početni iznos (neobavezno)',
        'initial_amount_help' => 'Iznos se oduzima od neraspoređenog. Ostavi prazno za praznu kasicu.',
        'link_to' => 'Poveži s (neobavezno)',
        'link_goal' => 'Cilj',
        'link_none' => 'Bez poveznice',
        'select_goal' => 'Odaberi cilj',
        'save_pot' => 'Spremi kasicu',
        'save_changes' => 'Spremi promjene',
    ],

    'fund' => [
        'title' => 'Uplata u kasicu',
        'heading' => 'Uplata u kasicu: :name',
        'submit' => 'Uplati u kasicu',
        'note_placeholder' => 'npr. Mjesečna štednja',
        'available' => 'Dostupno za raspoređivanje: :amount (neraspoređeno)',
    ],

    'move' => [
        'title' => 'Premjesti sredstva',
        'heading' => 'Premjesti iz kasice: :name',
        'to' => 'Premjesti u',
        'select_pot' => 'Odaberi kasicu',
        'no_others_short' => 'Nema drugih kasica',
        'no_others' => 'Nema drugih kasica na ovom računu',
        'submit' => 'Premjesti sredstva',
        'note_placeholder' => 'npr. Prijenos za godišnji',
    ],

    'withdraw' => [
        'heading' => 'Podizanje iz kasice: :name',
        'note_placeholder' => 'npr. Podizanje',
    ],

    'available_in' => 'Dostupno (:name): :amount',

    'errors' => [
        'enter_name' => 'Unesi naziv ove kasice.',
        'select_account' => 'Odaberi račun za ovu kasicu.',
        'amount_exceeds_unallocated' => 'Iznos premašuje neraspoređeno stanje.',
        'amount_exceeds_unallocated_available' => 'Iznos premašuje neraspoređeno stanje (dostupno: :amount).',
        'amount_exceeds_pot_balance' => 'Iznos premašuje stanje kasice „:name” (dostupno: :amount).',
    ],

    'toast' => [
        'pot_created' => 'Kasica je napravljena.',
        'pot_updated' => 'Kasica je ažurirana.',
        'pot_funded' => 'Uplata u kasicu je izvršena.',
        'withdrawn' => 'Podignuto iz kasice.',
        'funds_moved' => 'Sredstva su premještena.',
        'pot_archived' => 'Kasica je arhivirana.',
        'pot_restored' => 'Kasica je vraćena.',
    ],
];
