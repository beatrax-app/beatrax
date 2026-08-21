<?php

declare(strict_types=1);

return [
    'page_title' => 'Kasice · Beatrax',
    'heading' => 'Kasice',
    'subtitle' => 'Virtuelna podstanja koja se uvek sabiraju u stvarno stanje tvog računa.',
    'add_pot' => 'Dodaj kasicu',

    'pot_fallback' => 'kasica',

    'empty' => [
        'heading' => 'Još nema kasica',
        'body' => 'Napravi virtuelna podstanja unutar bilo kog računa da središ svoj novac bez stvarnog bankovnog prenosa.',
        'cta' => 'Dodaj svoju prvu kasicu',
        'no_accounts_cta' => 'Uvezi izvod',
    ],

    'common' => [
        'cancel' => 'Otkaži',
        'amount' => 'Iznos',
        'note_optional' => 'Beleška (opciono)',
    ],

    'actions' => [
        'fund' => 'Uplati',
        'move' => 'Premesti',
        'edit' => 'Izmeni',
        'withdraw' => 'Podigni',
        'archive' => 'Arhiviraj',
        'restore' => 'Vrati',
    ],

    'recon' => [
        'over_allocated' => 'Kasice premašuju stvarno stanje za :amount — uravnoteži da to ispraviš',
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

    'archive_confirm' => 'Arhivirati ovu kasicu? Stanje od :amount vratiće se u neraspoređeno.',
    'confirm_archive_aria' => 'Potvrdi arhiviranje kasice :name',
    'more_actions_aria' => 'Više radnji za :name',

    'history' => [
        'show' => 'Prikaži istoriju ↓',
        'hide' => 'Sakrij istoriju ↑',
    ],

    'movement' => [
        'fund' => 'Uplata',
        'withdraw' => 'Podizanje',
        'moved_from' => 'Premešteno iz :name',
        'moved_to' => 'Premešteno u :name',
    ],

    'archived' => [
        'toggle' => 'Arhivirane kasice (:count)',
        'badge' => 'Arhivirano',
    ],

    'form' => [
        'create_title' => 'Napravi kasicu',
        'edit_title' => 'Izmeni kasicu',
        'create_subtitle' => 'Imenuj virtuelno podstanje unutar računa.',
        'edit_subtitle' => 'Ažuriraj naziv ili vezu ove kasice.',
        'name' => 'Naziv',
        'name_placeholder' => 'npr. Fond za odmor',
        'account' => 'Račun',
        'select_account' => 'Izaberi račun',
        'initial_amount' => 'Početni iznos (opciono)',
        'initial_amount_help' => 'Iznos se oduzima od neraspoređenog. Ostavi prazno da napraviš praznu kasicu.',
        'link_to' => 'Poveži sa (opciono)',
        'link_goal' => 'Cilj',
        'link_none' => 'Ništa',
        'select_goal' => 'Izaberi cilj',
        'save_pot' => 'Sačuvaj kasicu',
        'save_changes' => 'Sačuvaj izmene',
    ],

    'fund' => [
        'title' => 'Uplati u kasicu',
        'heading' => 'Uplati u :name',
        'submit' => 'Uplati u kasicu',
        'note_placeholder' => 'npr. Mesečna štednja',
        'available' => 'Dostupno za raspoređivanje: :amount (neraspoređeno)',
    ],

    'move' => [
        'title' => 'Premesti sredstva',
        'heading' => 'Premesti iz :name',
        'to' => 'Premesti u',
        'select_pot' => 'Izaberi kasicu',
        'no_others_short' => 'Nema drugih kasica',
        'no_others' => 'Nema drugih kasica na ovom računu',
        'submit' => 'Premesti sredstva',
        'note_placeholder' => 'npr. Prenos za odmor',
    ],

    'withdraw' => [
        'heading' => 'Podigni iz :name',
        'note_placeholder' => 'npr. Podizanje',
    ],

    'available_in' => 'Dostupno u :name: :amount',

    'errors' => [
        'enter_name' => 'Unesi naziv ove kasice.',
        'select_account' => 'Izaberi račun za ovu kasicu.',
        'amount_exceeds_unallocated' => 'Iznos premašuje neraspoređeno stanje.',
        'amount_exceeds_unallocated_available' => 'Iznos premašuje neraspoređeno stanje (dostupno :amount).',
        'amount_exceeds_pot_balance' => 'Iznos premašuje stanje u :name (dostupno :amount).',
    ],

    'toast' => [
        'pot_created' => 'Kasica je napravljena.',
        'pot_updated' => 'Kasica je ažurirana.',
        'pot_funded' => 'Uplata u kasicu je izvršena.',
        'withdrawn' => 'Podignuto iz kasice.',
        'funds_moved' => 'Sredstva su premeštena.',
        'pot_archived' => 'Kasica je arhivirana.',
        'pot_restored' => 'Kasica je vraćena.',
    ],
];
