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
        'aria' => ':name: :pct % fuldført',
    ],

    'card' => [
        'target_date' => 'Måldato: :date',
    ],

    'projection' => [
        'target_reached' => 'Målet er nået',
        'closed_short' => 'Lukket før målet',
        'add_contributions' => 'Tilføj indbetalinger for at se en prognose',
        'not_enough_history' => 'Endnu ikke nok historik til at anslå en dato',
        'no_recent_contributions' => 'Ingen nylige indbetalinger at anslå ud fra',
        'too_far_to_date' => 'For langt ude i fremtiden til en dato i dette tempo',
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
        'mark_complete_caption' => 'Markér',
        'archive' => 'Arkivér',
        'restore' => 'Gendan',
    ],

    'archived_disclosure' => 'Arkiveret mål (:count)|Arkiverede mål (:count)',

    'form' => [
        'title_edit' => 'Redigér mål',
        'title_create' => 'Opret et opsparingsmål',
        'subtitle_edit' => 'Opdatér navn, målbeløb, dato eller tilknyttet pulje.',
        'subtitle_create' => 'Angiv et målbeløb og en dato for at følge din opsparing.',
        'name' => 'Navn',
        'name_placeholder' => 'f.eks. Buffer',
        'target_amount' => 'Målbeløb (:currency)',
        'target_date' => 'Måldato',
        'linked_pot' => 'Tilknyttet opsparingspulje (valgfrit)',
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
        'date_invalid' => 'Vælg en rigtig dato.',
        'date_before_start' => 'Vælg en dato på eller efter målets startdato.',
        'generic' => 'Målet kunne ikke gemmes. Tjek felterne, og prøv igen.',
        'amount' => 'Indtast et gyldigt beløb større end nul.',
        'pot_linked_category' => 'Denne pulje er tilknyttet en kategori. Fjern den tilknytning på siden Opsparingspuljer først.',
        'pot_already_linked' => 'Denne pulje sparer allerede op til et andet mål. Fjern tilknytningen der først.',
        'pot_missing' => 'Den pulje er ikke længere tilgængelig. Vælg en anden, eller lad dette mål stå uden tilknytning.',
    ],
];
