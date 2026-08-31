<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Mål',
        'subtitle' => 'Følg framgangen mot sparemålene dine.',
        'add_goal' => 'Legg til mål',
    ],

    'empty' => [
        'heading' => 'Ingen mål ennå',
        'body' => 'Angi et målbeløp og en dato for å begynne å følge sparingen din.',
        'add_first' => 'Legg til ditt første mål',
    ],

    'status' => [
        'overdue' => 'Forfalt',
        'reached' => 'Nådd',
        'completed' => 'Fullført',
        'archived' => 'Arkivert',
    ],

    'row' => [
        'edit' => 'Rediger',
    ],

    'progress' => [
        'aria' => ':name: :pct % fullført',
    ],

    'card' => [
        'target_date' => 'Måldato: :date',
    ],

    'projection' => [
        'target_reached' => 'Målet er nådd',
        'closed_short' => 'Lukket før målet',
        'add_contributions' => 'Legg til innskudd for å se en prognose',
        'not_enough_history' => 'Ennå ikke nok historikk til å anslå en dato',
        'no_recent_contributions' => 'Ingen nylige innskudd å anslå ut fra',
        'too_far_to_date' => 'For langt fram i tid til en dato i dette tempoet',
        'est' => 'Ca. :date ·',
        'projection_note' => '(prognose)',
        'projected' => 'Forventet: :date',
    ],

    'archive' => [
        'confirm_question' => 'Vil du arkivere dette målet?',
        'close' => 'Lukk',
        'confirm_aria' => 'Bekreft arkivering av :name',
        'archive' => 'Arkiver',
    ],

    'actions' => [
        'more_aria' => 'Flere handlinger for :name',
        'mark_complete' => 'Merk som fullført',
        'mark_complete_caption' => 'Merk',
        'archive' => 'Arkiver',
        'restore' => 'Gjenopprett',
    ],

    'archived_disclosure' => 'Arkivert mål (:count)|Arkiverte mål (:count)',

    'form' => [
        'title_edit' => 'Rediger mål',
        'title_create' => 'Opprett et sparemål',
        'subtitle_edit' => 'Oppdater navn, målbeløp, dato eller tilknyttet sparepott.',
        'subtitle_create' => 'Angi et målbeløp og en dato for å følge sparingen din.',
        'name' => 'Navn',
        'name_placeholder' => 'f.eks. Bufferkonto',
        'target_amount' => 'Målbeløp (:currency)',
        'target_date' => 'Måldato',
        'linked_pot' => 'Tilknyttet sparepott (valgfritt)',
        'no_pot' => 'Ingen sparepott — bruk overføringssporing',
        'linked_pot_help' => 'Når den er tilknyttet, styrer saldoen i sparepotten hvor langt dette målet er kommet.',
        'save_changes' => 'Lagre endringer',
        'save_goal' => 'Lagre mål',
        'close' => 'Lukk',
    ],

    'summary' => [
        'see_all' => 'Se alle →',
        'no_goals' => 'Ingen mål ennå.',
        'add_first' => 'Legg til ditt første mål →',
    ],

    'notices' => [
        'goal_created' => 'Målet er opprettet.',
        'goal_updated' => 'Målet er oppdatert.',
        'goal_marked_complete' => 'Målet er merket som fullført.',
        'goal_archived' => 'Målet er arkivert.',
        'goal_restored' => 'Målet er gjenopprettet.',
    ],

    'errors' => [
        'name' => 'Skriv inn et navn på målet ditt.',
        'date' => 'Velg en måldato.',
        'date_invalid' => 'Velg en reell dato.',
        'date_before_start' => 'Velg en dato på eller etter målets startdato.',
        'generic' => 'Målet kunne ikke lagres. Sjekk feltene og prøv igjen.',
        'amount' => 'Skriv inn et gyldig beløp større enn null.',
        'pot_linked_category' => 'Denne sparepotten er tilknyttet en kategori. Fjern den tilknytningen på Sparepotter-siden først.',
        'pot_already_linked' => 'Denne sparepotten sparer allerede til et annet mål. Fjern tilknytningen der først.',
        'pot_missing' => 'Den sparepotten er ikke lenger tilgjengelig. Velg en annen, eller la dette målet stå uten tilknytning.',
    ],
];
