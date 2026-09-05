<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Scenario-editor — :name',
    'rename_aria' => 'Scenario hernoemen',
    'save' => 'Opslaan',
    'save_changes' => 'Wijzigingen opslaan',
    'cancel' => 'Annuleren',
    'rename' => 'Hernoemen',
    'confirm_delete' => 'Verwijderen bevestigen',
    'delete_scenario' => 'Scenario verwijderen',
    'delete_confirm' => 'Dit scenario verwijderen?',

    'mutations_count' => 'Mutaties (:count)',
    'no_mutations' => 'Nog geen mutaties. Voeg er hieronder een toe om te zien hoe dit scenario zich verhoudt tot je basislijn.',
    'editing' => 'Bewerken — :kind',
    'edit' => 'Bewerken',
    'remove' => 'Verwijderen',

    'add_mutation' => '+ Mutatie toevoegen',
    'add_to_scenario' => 'Aan scenario toevoegen',
    'pick_kind' => 'Kies een soort mutatie:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Een reeks opzeggen',
            'desc' => 'Laat elke geprojecteerde voorkomst van een goedgekeurde reeks vervallen.',
        ],
        'add_one_off' => [
            'title' => 'Een eenmalige afschrijving of bijschrijving toevoegen',
            'desc' => 'Eén hypothetische gebeurtenis op een specifieke datum.',
        ],
        'add_recurring' => [
            'title' => 'Een terugkerende reeks toevoegen',
            'desc' => 'Een hypothetisch nieuw abonnement of inkomstenstroom.',
        ],
        'change_series_amount' => [
            'title' => 'Een reeksbedrag wijzigen',
            'desc' => 'Modelleer een prijsstijging of -daling op een bestaande reeks.',
        ],
        'shift_series_date' => [
            'title' => 'Een reeksdatum verschuiven',
            'desc' => 'Verplaats de volgende of alle daaropvolgende voorkomsten naar een andere datum.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Op te zeggen reeks',
        'pick_series' => '— kies een reeks —',
        'date' => 'Datum',
        'amount' => 'Bedrag',
        'currency' => 'Valuta',
        'direction' => 'Richting',
        'expense_long' => 'Uitgave (geld eruit)',
        'income_long' => 'Inkomsten (geld erin)',
        'note' => 'Notitie (optioneel)',
        'start_date' => 'Startdatum',
        'expense' => 'Uitgave',
        'income' => 'Inkomsten',
        'cadence' => 'Frequentie',
        'cadence_weekly' => 'Wekelijks',
        'cadence_monthly' => 'Maandelijks',
        'cadence_quarterly' => 'Per kwartaal',
        'cadence_yearly' => 'Jaarlijks',
        'series' => 'Reeks',
        'new_amount' => 'Nieuw bedrag',
        'new_next_date' => 'Nieuwe volgende datum',
        'scope' => 'Bereik',
        'scope_legend' => 'Welke voorkomsten verschuiven',
        'scope_next' => 'Alleen de volgende voorkomst',
        'scope_all' => 'Alle daaropvolgende voorkomsten',
    ],

    'whatif' => [
        'trigger' => 'Wat-als modelleren',
        'menu_aria' => 'Wat-als modelleren voor :name',
        'model_cancellation' => 'Opzegging modelleren',
        'model_amount_change' => 'Bedragswijziging modelleren…',
        'amount_dialog_aria' => 'Bedragswijziging modelleren voor :name',
        'current_amount' => 'Huidig bedrag',
        'new_amount' => 'Nieuw bedrag',
    ],

    'series_name_fallback' => 'reeks',

    'template' => [
        'cancel' => 'Opzeggen :name',
        'change_amount' => 'Bedrag van :name wijzigen',
    ],

    'summary' => [
        'cancel' => 'Opzeggen :name',
        'series_fallback' => 'reeks #:id',
        'one_off' => ':amount :currency op :date',
        'recurring' => ':amount :currency :cadence vanaf :date',
        'change_amount' => ':name: nieuw bedrag :amount',
        'shift' => ':name: verschuif :scope naar :date',
        'scope_all' => 'alle daaropvolgende',
        'scope_next' => 'volgende',
    ],

    'toast' => [
        'created' => 'Scenario ":name" aangemaakt.',
        'deleted' => 'Scenario verwijderd.',
        'renamed' => 'Scenario hernoemd.',
        'mutation_added' => 'Mutatie toegevoegd.',
        'mutation_updated' => 'Mutatie bijgewerkt.',
        'mutation_removed' => 'Mutatie verwijderd.',
    ],

    'errors' => [
        'name_empty' => 'Scenarionaam mag niet leeg zijn.',
        'name_too_long' => 'Scenarionaam mag maximaal :max teken bevatten.|Scenarionaam mag maximaal :max tekens bevatten.',
        'name_taken' => 'Er bestaat al een scenario met die naam.',
        'date_out_of_range' => 'Die datum valt buiten elke prognosehorizon — van vandaag tot :days dag vooruit — dus dit scenario zou niets veranderen.|Die datum valt buiten elke prognosehorizon — van vandaag tot :days dagen vooruit — dus dit scenario zou niets veranderen.',
        'pick_kind_first' => 'Kies eerst een soort mutatie.',
        'amount_positive' => 'Bedrag moet een positief getal zijn.',
        'scenario_gone' => 'Dit scenario bestaat niet meer — het is elders verwijderd. Kies een ander scenario of maak een nieuw.',
        'mutation_gone' => 'Deze wijziging bestaat niet meer — ze is elders verwijderd. Sluit de editor en voeg haar opnieuw toe als je haar nog wilt.',
    ],
];
