<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Cíle',
        'subtitle' => 'Sleduj pokrok ke svým spořicím cílům.',
        'add_goal' => 'Přidat cíl',
    ],

    'empty' => [
        'heading' => 'Zatím žádné cíle',
        'body' => 'Nastav cílovou částku a datum a začni sledovat pokrok ve spoření.',
        'add_first' => 'Přidat první cíl',
    ],

    'status' => [
        'overdue' => 'Po termínu',
        'reached' => 'Dosaženo',
        'completed' => 'Dokončeno',
        'archived' => 'Archivováno',
    ],

    'row' => [
        'edit' => 'Upravit',
    ],

    'progress' => [
        'aria' => ':name: hotovo :pct %',
    ],

    'card' => [
        'target_date' => 'Cílové datum: :date',
    ],

    'projection' => [
        'target_reached' => 'Cíl dosažen',
        'closed_short' => 'Uzavřeno před dosažením cíle',
        'add_contributions' => 'Přidej vklady a zobrazí se odhad',
        'not_enough_history' => 'Zatím není dost historie pro odhad data',
        'no_recent_contributions' => 'Žádné nedávné příspěvky, ze kterých by šlo odhadovat',
        'est' => 'Odh. :date ·',
        'projection_note' => '(odhad)',
        'projected' => 'Odhad: :date',
    ],

    'archive' => [
        'confirm_question' => 'Archivovat tento cíl?',
        'close' => 'Zavřít',
        'confirm_aria' => 'Potvrdit archivaci — cíl: :name',
        'archive' => 'Archivovat',
    ],

    'actions' => [
        'more_aria' => 'Další akce — cíl: :name',
        'mark_complete' => 'Označit jako dokončené',
        'archive' => 'Archivovat',
        'restore' => 'Obnovit',
    ],

    'archived_disclosure' => 'Archivované cíle (:count)',

    'form' => [
        'title_edit' => 'Upravit cíl',
        'title_create' => 'Vytvořit spořicí cíl',
        'subtitle_edit' => 'Uprav název, cílovou částku, datum nebo propojenou spořicí obálku.',
        'subtitle_create' => 'Nastav cílovou částku a datum a sleduj pokrok ve spoření.',
        'name' => 'Název',
        'name_placeholder' => 'např. Rezerva na horší časy',
        'target_amount' => 'Cílová částka (:currency)',
        'target_date' => 'Cílové datum',
        'linked_pot' => 'Propojená spořicí obálka (volitelné)',
        'no_pot' => 'Bez obálky — použít sledování převodů',
        'linked_pot_help' => 'Po propojení určuje pokrok tohoto cíle zůstatek spořicí obálky.',
        'save_changes' => 'Uložit změny',
        'save_goal' => 'Uložit cíl',
        'close' => 'Zavřít',
    ],

    'summary' => [
        'see_all' => 'Zobrazit vše →',
        'no_goals' => 'Zatím žádné cíle.',
        'add_first' => 'Přidat první cíl →',
    ],

    'notices' => [
        'goal_created' => 'Cíl vytvořen.',
        'goal_updated' => 'Cíl upraven.',
        'goal_marked_complete' => 'Cíl označen jako dokončený.',
        'goal_archived' => 'Cíl archivován.',
        'goal_restored' => 'Cíl obnoven.',
    ],

    'errors' => [
        'name' => 'Zadej název svého cíle.',
        'date' => 'Zvol cílové datum.',
        'date_invalid' => 'Zvolte skutečné datum.',
        'generic' => 'Cíl se nepodařilo uložit. Zkontrolujte pole a zkuste to znovu.',
        'amount' => 'Zadej platnou částku větší než nula.',
        'pot_linked_category' => 'Tato spořicí obálka je propojená s kategorií. Nejdřív toto propojení odstraň na stránce Spořicí obálky.',
    ],
];
