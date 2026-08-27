<?php

declare(strict_types=1);

return [
    'page_title' => 'Sporiace obálky · Beatrax',
    'heading' => 'Sporiace obálky',
    'subtitle' => 'Virtuálne časti zostatku, ktoré vždy dajú dokopy skutočný zostatok účtu.',
    'add_pot' => 'Pridať obálku',

    'pot_fallback' => 'obálka',

    'empty' => [
        'heading' => 'Zatiaľ žiadne obálky',
        'body' => 'Vytvor v ktoromkoľvek účte virtuálne časti zostatku a usporiadaj si peniaze bez skutočného bankového prevodu.',
        'cta' => 'Pridaj prvú obálku',
        'no_accounts_cta' => 'Importovať výpis z účtu',
    ],

    'common' => [
        'cancel' => 'Zrušiť',
        'amount' => 'Suma',
        'note_optional' => 'Poznámka (voliteľné)',
    ],

    'actions' => [
        'fund' => 'Vložiť',
        'move' => 'Presunúť',
        'edit' => 'Upraviť',
        'withdraw' => 'Vybrať',
        'archive' => 'Archivovať',
        'restore' => 'Obnoviť',
    ],

    'recon' => [
        'over_allocated' => 'Obálky presahujú skutočný zostatok o :amount — vyrovnaj to',
        'real_balance' => 'Skutočný zostatok:',
        'allocated' => 'Priradené:',
        'unallocated' => 'Nepriradené:',
    ],

    'chip' => [
        'goal' => 'Cieľ:',
        'goal_name_fallback' => 'Cieľ',
        'category_fallback' => 'Kategória',
    ],

    'coverage' => [
        'spent' => 'minuté',
        'in_pot' => 'v obálke',
    ],

    'archive_confirm' => 'Archivovať túto obálku? Zostatok :amount sa vráti medzi nepriradené.',
    'confirm_archive_aria' => 'Potvrdiť archiváciu — obálka: :name',
    'more_actions_aria' => 'Ďalšie akcie — obálka: :name',

    'history' => [
        'show' => 'Zobraziť históriu ↓',
        'hide' => 'Skryť históriu ↑',
    ],

    'movement' => [
        'fund' => 'Vklad',
        'withdraw' => 'Výber',
        'moved_from' => 'Presunuté z obálky: :name',
        'moved_to' => 'Presunuté do obálky: :name',
    ],

    'archived' => [
        'toggle' => 'Archivované obálky (:count)',
        'badge' => 'Archivované',
    ],

    'form' => [
        'create_title' => 'Vytvoriť sporiacu obálku',
        'edit_title' => 'Upraviť obálku',
        'create_subtitle' => 'Pomenuj virtuálnu časť zostatku v rámci účtu.',
        'edit_subtitle' => 'Uprav názov alebo prepojenie tejto obálky.',
        'name' => 'Názov',
        'name_placeholder' => 'napr. Fond na dovolenku',
        'account' => 'Účet',
        'select_account' => 'Vyber účet',
        'initial_amount' => 'Počiatočná suma (voliteľné)',
        'initial_amount_help' => 'Suma sa odpočíta z nepriradených. Ak chceš prázdnu obálku, nechaj pole prázdne.',
        'link_to' => 'Prepojiť s (voliteľné)',
        'link_goal' => 'Cieľ',
        'link_none' => 'Nič',
        'select_goal' => 'Vyber cieľ',
        'save_pot' => 'Uložiť obálku',
        'save_changes' => 'Uložiť zmeny',
    ],

    'fund' => [
        'title' => 'Vložiť do obálky',
        'heading' => 'Vklad do obálky: :name',
        'submit' => 'Vložiť do obálky',
        'note_placeholder' => 'napr. Mesačné sporenie',
        'available' => 'K dispozícii na priradenie: :amount (nepriradené)',
    ],

    'move' => [
        'title' => 'Presunúť prostriedky',
        'heading' => 'Presun z obálky: :name',
        'to' => 'Presunúť do',
        'select_pot' => 'Vyber obálku',
        'no_others_short' => 'Žiadne iné obálky',
        'no_others' => 'V tomto účte nie sú iné obálky',
        'submit' => 'Presunúť prostriedky',
        'note_placeholder' => 'napr. Prevod na dovolenku',
    ],

    'withdraw' => [
        'heading' => 'Výber z obálky: :name',
        'note_placeholder' => 'napr. Výber',
    ],

    'available_in' => 'K dispozícii — :name: :amount',

    'errors' => [
        'enter_name' => 'Zadaj názov tejto obálky.',
        'select_account' => 'Vyber účet pre túto obálku.',
        'amount_exceeds_unallocated' => 'Suma presahuje nepriradený zostatok.',
        'amount_exceeds_unallocated_available' => 'Suma presahuje nepriradený zostatok (k dispozícii :amount).',
        'amount_exceeds_pot_balance' => 'Suma presahuje zostatok v obálke „:name“ (k dispozícii :amount).',
        'generic' => 'Obálku sa nepodarilo uložiť. Skontrolujte polia a skúste to znova.',
        'amount_invalid' => 'Zadajte sumu väčšiu ako nula.',
        'goal_already_linked' => 'Tento cieľ už má aktívnu prepojenú obálku. Najprv ju archivujte.',
    ],

    'toast' => [
        'pot_created' => 'Obálka vytvorená.',
        'pot_updated' => 'Obálka aktualizovaná.',
        'pot_funded' => 'Obálka doplnená.',
        'withdrawn' => 'Vybrané z obálky.',
        'funds_moved' => 'Prostriedky presunuté.',
        'pot_archived' => 'Obálka archivovaná.',
        'pot_restored' => 'Obálka obnovená.',
    ],
];
