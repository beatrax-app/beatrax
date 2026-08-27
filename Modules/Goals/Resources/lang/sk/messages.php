<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Ciele',
        'subtitle' => 'Sleduj pokrok pri svojich sporiacich cieľoch.',
        'add_goal' => 'Pridať cieľ',
    ],

    'empty' => [
        'heading' => 'Zatiaľ žiadne ciele',
        'body' => 'Nastav cieľovú sumu a dátum a začni sledovať pokrok v sporení.',
        'add_first' => 'Pridaj prvý cieľ',
    ],

    'status' => [
        'overdue' => 'Po termíne',
        'reached' => 'Dosiahnutý',
        'completed' => 'Dokončený',
        'archived' => 'Archivovaný',
    ],

    'row' => [
        'edit' => 'Upraviť',
    ],

    'progress' => [
        'aria' => ':name: dokončené na :pct%',
    ],

    'card' => [
        'target_date' => 'Cieľový dátum: :date',
    ],

    'projection' => [
        'target_reached' => 'Cieľ dosiahnutý',
        'closed_short' => 'Uzavreté pred dosiahnutím cieľa',
        'add_contributions' => 'Pridaj príspevky a zobrazí sa prognóza',
        'not_enough_history' => 'Zatiaľ nie je dosť histórie na odhad dátumu',
        'no_recent_contributions' => 'Žiadne nedávne vklady, z ktorých by sa dalo odhadovať',
        'est' => 'Odhad :date ·',
        'projection_note' => '(prognóza)',
        'projected' => 'Prognóza: :date',
    ],

    'archive' => [
        'confirm_question' => 'Archivovať tento cieľ?',
        'close' => 'Zavrieť',
        'confirm_aria' => 'Potvrdiť archiváciu — cieľ: :name',
        'archive' => 'Archivovať',
    ],

    'actions' => [
        'more_aria' => 'Ďalšie akcie — cieľ: :name',
        'mark_complete' => 'Označiť ako dokončený',
        'archive' => 'Archivovať',
        'restore' => 'Obnoviť',
    ],

    'archived_disclosure' => 'Archivované ciele (:count)',

    'form' => [
        'title_edit' => 'Upraviť cieľ',
        'title_create' => 'Vytvoriť sporiaci cieľ',
        'subtitle_edit' => 'Uprav názov, cieľovú sumu, dátum alebo prepojenú sporiacu obálku.',
        'subtitle_create' => 'Nastav cieľovú sumu a dátum a sleduj pokrok v sporení.',
        'name' => 'Názov',
        'name_placeholder' => 'napr. Núdzová rezerva',
        'target_amount' => 'Cieľová suma (:currency)',
        'target_date' => 'Cieľový dátum',
        'linked_pot' => 'Prepojená sporiaca obálka (voliteľné)',
        'no_pot' => 'Bez obálky — použiť sledovanie prevodov',
        'linked_pot_help' => 'Po prepojení určuje pokrok tohto cieľa zostatok sporiacej obálky.',
        'save_changes' => 'Uložiť zmeny',
        'save_goal' => 'Uložiť cieľ',
        'close' => 'Zavrieť',
    ],

    'summary' => [
        'see_all' => 'Zobraziť všetko →',
        'no_goals' => 'Zatiaľ žiadne ciele.',
        'add_first' => 'Pridaj prvý cieľ →',
    ],

    'notices' => [
        'goal_created' => 'Cieľ vytvorený.',
        'goal_updated' => 'Cieľ aktualizovaný.',
        'goal_marked_complete' => 'Cieľ označený ako dokončený.',
        'goal_archived' => 'Cieľ archivovaný.',
        'goal_restored' => 'Cieľ obnovený.',
    ],

    'errors' => [
        'name' => 'Zadaj názov cieľa.',
        'date' => 'Vyber cieľový dátum.',
        'date_invalid' => 'Vyberte skutočný dátum.',
        'generic' => 'Cieľ sa nepodarilo uložiť. Skontrolujte polia a skúste to znova.',
        'amount' => 'Zadaj platnú sumu väčšiu ako nula.',
        'pot_linked_category' => 'Táto sporiaca obálka je prepojená s kategóriou. Najprv toto prepojenie odstráň na stránke Sporiace obálky.',
    ],
];
