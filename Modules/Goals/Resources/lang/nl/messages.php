<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Doelen',
        'subtitle' => 'Volg de voortgang naar je spaardoelen.',
        'add_goal' => 'Doel toevoegen',
    ],

    'empty' => [
        'heading' => 'Nog geen doelen',
        'body' => 'Stel een streefbedrag en -datum in om je spaarvoortgang te volgen.',
        'add_first' => 'Voeg je eerste doel toe',
    ],

    'status' => [
        'overdue' => 'Verlopen',
        'reached' => 'Bereikt',
        'completed' => 'Voltooid',
        'archived' => 'Gearchiveerd',
    ],

    'row' => [
        'edit' => 'Bewerken',
    ],

    'progress' => [
        'aria' => ':name: :pct% voltooid',
    ],

    'card' => [
        'target_date' => 'Streefdatum: :date',
    ],

    'projection' => [
        'target_reached' => 'Doel bereikt',
        'closed_short' => 'Afgesloten vóór het doel',
        'add_contributions' => 'Voeg bijdragen toe om een prognose te zien',
        'not_enough_history' => 'Nog te weinig historie voor een prognosedatum',
        'no_recent_contributions' => 'Geen recente inleg om een prognose op te baseren',
        'too_far_to_date' => 'In dit tempo te ver weg voor een datum',
        'est' => 'Ca. :date ·',
        'projection_note' => '(prognose)',
        'projected' => 'Verwacht: :date',
    ],

    'archive' => [
        'confirm_question' => 'Dit doel archiveren?',
        'close' => 'Sluiten',
        'confirm_aria' => 'Archiveren van :name bevestigen',
        'archive' => 'Archiveren',
    ],

    'actions' => [
        'more_aria' => 'Meer acties voor :name',
        'mark_complete' => 'Markeren als voltooid',
        'mark_complete_caption' => 'Markeren',
        'archive' => 'Archiveren',
        'restore' => 'Herstellen',
    ],

    'archived_disclosure' => 'Gearchiveerd doel (:count)|Gearchiveerde doelen (:count)',

    'form' => [
        'title_edit' => 'Doel bewerken',
        'title_create' => 'Een spaardoel maken',
        'subtitle_edit' => 'Werk de naam, het streefbedrag, de datum of de gekoppelde pot bij.',
        'subtitle_create' => 'Stel een streefbedrag en -datum in om je spaarvoortgang te volgen.',
        'name' => 'Naam',
        'name_placeholder' => 'bijv. Noodfonds',
        'target_amount' => 'Streefbedrag (:currency)',
        'target_date' => 'Streefdatum',
        'linked_pot' => 'Gekoppelde pot (optioneel)',
        'no_pot' => 'Geen pot — gebruik overboekingsregistratie',
        'linked_pot_help' => 'Bij koppeling bepaalt het saldo van de pot de voortgang van dit doel.',
        'save_changes' => 'Wijzigingen opslaan',
        'save_goal' => 'Doel opslaan',
        'close' => 'Sluiten',
    ],

    'summary' => [
        'see_all' => 'Alles bekijken →',
        'no_goals' => 'Nog geen doelen.',
        'add_first' => 'Voeg je eerste doel toe →',
    ],

    'notices' => [
        'goal_created' => 'Doel aangemaakt.',
        'goal_updated' => 'Doel bijgewerkt.',
        'goal_marked_complete' => 'Doel gemarkeerd als voltooid.',
        'goal_archived' => 'Doel gearchiveerd.',
        'goal_restored' => 'Doel hersteld.',
    ],

    'errors' => [
        'name' => 'Voer een naam voor je doel in.',
        'date' => 'Kies een streefdatum.',
        'date_invalid' => 'Kies een bestaande datum.',
        'date_before_start' => 'Kies een datum op of na de startdatum van het doel.',
        'generic' => 'Dit doel kon niet worden opgeslagen. Controleer de velden en probeer het opnieuw.',
        'amount' => 'Voer een geldig bedrag groter dan nul in.',
        'pot_linked_category' => 'Deze pot is aan een categorie gekoppeld. Verwijder die koppeling eerst op de Potten-pagina.',
        'pot_already_linked' => 'Dit potje spaart al voor een ander doel. Verwijder die koppeling daar eerst.',
        'pot_missing' => 'Dat potje is niet meer beschikbaar. Kies een ander, of laat dit doel ongekoppeld.',
    ],
];
