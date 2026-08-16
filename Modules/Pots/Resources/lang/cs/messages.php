<?php

declare(strict_types=1);

return [
    'page_title' => 'Spořicí obálky · Beatrax',
    'heading' => 'Spořicí obálky',
    'subtitle' => 'Virtuální dílčí zůstatky, které vždycky dají dohromady skutečný zůstatek účtu.',
    'add_pot' => 'Přidat obálku',

    'pot_fallback' => 'obálka',

    'empty' => [
        'heading' => 'Zatím žádné spořicí obálky',
        'body' => 'Vytvářej v libovolném účtu virtuální dílčí zůstatky a rozděl si peníze bez skutečného bankovního převodu.',
        'cta' => 'Přidat první obálku',
    ],

    'common' => [
        'cancel' => 'Zrušit',
        'amount' => 'Částka',
        'note_optional' => 'Poznámka (volitelné)',
    ],

    'actions' => [
        'fund' => 'Vložit',
        'move' => 'Přesunout',
        'edit' => 'Upravit',
        'withdraw' => 'Vybrat',
        'archive' => 'Archivovat',
        'restore' => 'Obnovit',
    ],

    'recon' => [
        'over_allocated' => 'Obálky přesahují skutečný zůstatek o :amount — vyrovnej to',
        'real_balance' => 'Skutečný zůstatek:',
        'allocated' => 'Přiřazeno:',
        'unallocated' => 'Nepřiřazeno:',
    ],

    'chip' => [
        'goal' => 'Cíl:',
        'goal_name_fallback' => 'Cíl',
        'category_fallback' => 'Kategorie',
    ],

    'coverage' => [
        'spent' => 'utraceno',
        'in_pot' => 'v obálce',
    ],

    'archive_confirm' => 'Archivovat tuto obálku? Zůstatek :amount se vrátí mezi nepřiřazené.',
    'confirm_archive_aria' => 'Potvrdit archivaci — obálka: :name',
    'more_actions_aria' => 'Další akce — obálka: :name',

    'history' => [
        'show' => 'Zobrazit historii ↓',
        'hide' => 'Skrýt historii ↑',
    ],

    'movement' => [
        'fund' => 'Vklad',
        'withdraw' => 'Výběr',
        'moved_from' => 'Přesunuto z obálky: :name',
        'moved_to' => 'Přesunuto do obálky: :name',
    ],

    'archived' => [
        'toggle' => 'Archivované obálky (:count)',
        'badge' => 'Archivovaná',
    ],

    'form' => [
        'create_title' => 'Vytvořit spořicí obálku',
        'edit_title' => 'Upravit obálku',
        'create_subtitle' => 'Pojmenuj virtuální dílčí zůstatek v rámci účtu.',
        'edit_subtitle' => 'Uprav název nebo propojení této obálky.',
        'name' => 'Název',
        'name_placeholder' => 'např. Fond na dovolenou',
        'account' => 'Účet',
        'select_account' => 'Vyber účet',
        'initial_amount' => 'Počáteční částka (volitelné)',
        'initial_amount_help' => 'Částka se odečte z nepřiřazených. Nech prázdné a obálka vznikne prázdná.',
        'link_to' => 'Propojit s (volitelné)',
        'link_goal' => 'Cíl',
        'link_none' => 'Nic',
        'select_goal' => 'Vyber cíl',
        'save_pot' => 'Uložit obálku',
        'save_changes' => 'Uložit změny',
    ],

    'fund' => [
        'title' => 'Vložit do obálky',
        'heading' => 'Vklad do obálky: :name',
        'submit' => 'Vložit do obálky',
        'note_placeholder' => 'např. Měsíční spoření',
        'available' => 'K přiřazení: :amount (nepřiřazeno)',
    ],

    'move' => [
        'title' => 'Přesunout prostředky',
        'heading' => 'Přesun z obálky: :name',
        'to' => 'Přesunout do',
        'select_pot' => 'Vyber obálku',
        'no_others_short' => 'Žádné další obálky',
        'no_others' => 'Na tomto účtu nejsou žádné další obálky',
        'submit' => 'Přesunout prostředky',
        'note_placeholder' => 'např. Převod na dovolenou',
    ],

    'withdraw' => [
        'heading' => 'Výběr z obálky: :name',
        'note_placeholder' => 'např. Výběr',
    ],

    'available_in' => 'Dostupné (:name): :amount',

    'errors' => [
        'enter_name' => 'Zadej název této obálky.',
        'select_account' => 'Vyber pro tuto obálku účet.',
        'amount_exceeds_unallocated' => 'Částka přesahuje nepřiřazený zůstatek.',
        'amount_exceeds_unallocated_available' => 'Částka přesahuje nepřiřazený zůstatek (k dispozici: :amount).',
        'amount_exceeds_pot_balance' => 'Částka přesahuje zůstatek obálky „:name“ (k dispozici: :amount).',
    ],

    'toast' => [
        'pot_created' => 'Obálka vytvořena.',
        'pot_updated' => 'Obálka upravena.',
        'pot_funded' => 'Do obálky vloženo.',
        'withdrawn' => 'Z obálky vybráno.',
        'funds_moved' => 'Prostředky přesunuty.',
        'pot_archived' => 'Obálka archivována.',
        'pot_restored' => 'Obálka obnovena.',
    ],
];
