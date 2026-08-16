<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Mål',
        'subtitle' => 'Følg fremskridtet mod dine opsparingsmål.',
        'add_goal' => 'Tilføj mål',
    ],

    'empty' => [
        'heading' => 'Ingen mål endnu',
        'body' => 'Angiv et målbeløb og en dato for at begynde at følge din opsparing.',
        'add_first' => 'Tilføj dit første mål',
    ],

    'status' => [
        'overdue' => 'Overskredet',
        'reached' => 'Nået',
        'completed' => 'Fuldført',
        'archived' => 'Arkiveret',
    ],

    'row' => [
        'edit' => 'Redigér',
    ],

    'progress' => [
        'aria' => ':name: :pct% fuldført',
    ],

    'projection' => [
        'target_reached' => 'Målet er nået',
        'add_contributions' => 'Tilføj indbetalinger for at se en prognose',
        'building' => 'Bygger en prognose…',
        'est' => 'Ca. :date ·',
        'projection_note' => '(prognose)',
        'projected' => 'Forventet: :date',
    ],

    'archive' => [
        'confirm_question' => 'Vil du arkivere dette mål?',
        'close' => 'Luk',
        'confirm_aria' => 'Bekræft arkivering af :name',
        'archive' => 'Arkivér',
    ],

    'actions' => [
        'more_aria' => 'Flere handlinger for :name',
        'mark_complete' => 'Markér som fuldført',
        'archive' => 'Arkivér',
        'restore' => 'Gendan',
    ],

    'archived_disclosure' => 'Arkiverede mål (:count)',

    'form' => [
        'title_edit' => 'Redigér mål',
        'title_create' => 'Opret et opsparingsmål',
        'subtitle_edit' => 'Opdatér navn, målbeløb, dato eller tilknyttet konto.',
        'subtitle_create' => 'Angiv et målbeløb og en dato for at følge din opsparing.',
        'name' => 'Navn',
        'name_placeholder' => 'f.eks. Buffer',
        'target_amount' => 'Målbeløb (:currency)',
        'target_date' => 'Måldato',
        'savings_account' => 'Opsparingskonto (valgfrit)',
        'no_account' => 'Ingen konto — følg manuelt',
        'linked_pot' => 'Tilknyttet opsparingspulje (valgfrit)',
        'select_account_first' => 'Vælg først en konto',
        'no_pot' => 'Ingen pulje — brug overførselssporing',
        'linked_pot_help' => 'Når den er tilknyttet, styrer puljens saldo, hvor langt dette mål er nået.',
        'save_changes' => 'Gem ændringer',
        'save_goal' => 'Gem mål',
        'close' => 'Luk',
    ],

    'summary' => [
        'see_all' => 'Se alle →',
        'no_goals' => 'Ingen mål endnu.',
        'add_first' => 'Tilføj dit første mål →',
    ],

    'notices' => [
        'goal_created' => 'Målet er oprettet.',
        'goal_updated' => 'Målet er opdateret.',
        'goal_marked_complete' => 'Målet er markeret som fuldført.',
        'goal_archived' => 'Målet er arkiveret.',
        'goal_restored' => 'Målet er gendannet.',
    ],

    'errors' => [
        'name' => 'Indtast et navn til dit mål.',
        'date' => 'Vælg en måldato.',
        'amount' => 'Indtast et gyldigt beløb større end nul.',
        'pot_linked_category' => 'Denne pulje er tilknyttet en kategori. Fjern den tilknytning på siden Opsparingspuljer først.',
        'account_not_owned' => 'Kontoen tilhører ikke den godkendte bruger.',
    ],
];
