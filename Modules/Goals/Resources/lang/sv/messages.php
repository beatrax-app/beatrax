<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Mål',
        'subtitle' => 'Följ hur du närmar dig dina sparmål.',
        'add_goal' => 'Lägg till mål',
    ],

    'empty' => [
        'heading' => 'Inga mål än',
        'body' => 'Ange ett målbelopp och ett datum för att börja följa ditt sparande.',
        'add_first' => 'Lägg till ditt första mål',
    ],

    'status' => [
        'overdue' => 'Försenat',
        'reached' => 'Uppnått',
        'completed' => 'Slutfört',
        'archived' => 'Arkiverat',
    ],

    'row' => [
        'edit' => 'Redigera',
    ],

    'progress' => [
        'aria' => ':name: :pct % klart',
    ],

    'card' => [
        'target_date' => 'Måldatum: :date',
    ],

    'projection' => [
        'target_reached' => 'Målet är uppnått',
        'closed_short' => 'Avslutat före målet',
        'add_contributions' => 'Lägg till insättningar för att se en prognos',
        'not_enough_history' => 'Ännu inte tillräckligt med historik för att uppskatta ett datum',
        'no_recent_contributions' => 'Inga nyliga insättningar att uppskatta utifrån',
        'too_far_to_date' => 'För långt bort för ett datum i den här takten',
        'est' => 'Ca :date ·',
        'projection_note' => '(prognos)',
        'projected' => 'Prognos: :date',
    ],

    'archive' => [
        'confirm_question' => 'Vill du arkivera det här målet?',
        'close' => 'Stäng',
        'confirm_aria' => 'Bekräfta arkivering av :name',
        'archive' => 'Arkivera',
    ],

    'actions' => [
        'more_aria' => 'Fler åtgärder för :name',
        'mark_complete' => 'Markera som slutfört',
        'mark_complete_caption' => 'Markera',
        'archive' => 'Arkivera',
        'restore' => 'Återställ',
    ],

    'archived_disclosure' => 'Arkiverat mål (:count)|Arkiverade mål (:count)',

    'form' => [
        'title_edit' => 'Redigera mål',
        'title_create' => 'Skapa ett sparmål',
        'subtitle_edit' => 'Uppdatera namn, målbelopp, datum eller kopplad sparpott.',
        'subtitle_create' => 'Ange ett målbelopp och ett datum för att följa ditt sparande.',
        'name' => 'Namn',
        'name_placeholder' => 't.ex. Buffert',
        'target_amount' => 'Målbelopp (:currency)',
        'target_date' => 'Måldatum',
        'linked_pot' => 'Kopplad sparpott (valfritt)',
        'no_pot' => 'Ingen sparpott — använd överföringsspårning',
        'linked_pot_help' => 'När den är kopplad styr sparpottens saldo hur långt det här målet har kommit.',
        'save_changes' => 'Spara ändringar',
        'save_goal' => 'Spara mål',
        'close' => 'Stäng',
    ],

    'summary' => [
        'see_all' => 'Se alla →',
        'no_goals' => 'Inga mål än.',
        'add_first' => 'Lägg till ditt första mål →',
    ],

    'notices' => [
        'goal_created' => 'Målet skapades.',
        'goal_updated' => 'Målet uppdaterades.',
        'goal_marked_complete' => 'Målet markerades som slutfört.',
        'goal_archived' => 'Målet arkiverades.',
        'goal_restored' => 'Målet återställdes.',
    ],

    'errors' => [
        'name' => 'Ange ett namn för ditt mål.',
        'date' => 'Välj ett måldatum.',
        'date_invalid' => 'Välj ett riktigt datum.',
        'date_before_start' => 'Välj ett datum på eller efter målets startdatum.',
        'generic' => 'Målet kunde inte sparas. Kontrollera fälten och försök igen.',
        'amount' => 'Ange ett giltigt belopp större än noll.',
        'pot_linked_category' => 'Den här sparpotten är kopplad till en kategori. Ta bort den kopplingen på sidan Sparpotter först.',
        'pot_already_linked' => 'Den här sparpotten sparar redan till ett annat mål. Ta bort kopplingen där först.',
        'pot_missing' => 'Den sparpotten är inte längre tillgänglig. Välj en annan, eller lämna det här målet utan koppling.',
    ],
];
