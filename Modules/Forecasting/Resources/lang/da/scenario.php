<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Scenarieeditor — :name',
    'rename_aria' => 'Omdøb scenariet',
    'save' => 'Gem',
    'save_changes' => 'Gem ændringer',
    'cancel' => 'Annullér',
    'rename' => 'Omdøb',
    'confirm_delete' => 'Bekræft sletning',
    'delete_scenario' => 'Slet scenarie',
    'delete_confirm' => 'Slet dette scenarie?',

    'mutations_count' => 'Ændringer (:count)',
    'no_mutations' => 'Ingen ændringer endnu. Tilføj en nedenfor for at se, hvordan dette scenarie klarer sig i forhold til din basislinje.',
    'editing' => 'Redigerer — :kind',
    'edit' => 'Redigér',
    'remove' => 'Fjern',

    'add_mutation' => '+ Tilføj ændring',
    'add_to_scenario' => 'Føj til scenariet',
    'pick_kind' => 'Vælg en type ændring:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Opsig en serie',
            'desc' => 'Fjern alle forventede hændelser i en godkendt serie.',
        ],
        'add_one_off' => [
            'title' => 'Tilføj en engangsudgift eller engangsindtægt',
            'desc' => 'En enkelt hypotetisk hændelse på en bestemt dato.',
        ],
        'add_recurring' => [
            'title' => 'Tilføj en tilbagevendende serie',
            'desc' => 'Et hypotetisk nyt abonnement eller en ny indtægtskilde.',
        ],
        'change_series_amount' => [
            'title' => 'Ændr beløbet i en serie',
            'desc' => 'Simulér en prisstigning eller et prisfald i en eksisterende serie.',
        ],
        'shift_series_date' => [
            'title' => 'Flyt datoen i en serie',
            'desc' => 'Flyt den næste eller alle efterfølgende hændelser til en anden dato.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Serie, der skal opsiges',
        'pick_series' => '— vælg en serie —',
        'date' => 'Dato',
        'amount' => 'Beløb',
        'currency' => 'Valuta',
        'direction' => 'Retning',
        'expense_long' => 'Udgift (penge ud)',
        'income_long' => 'Indtægt (penge ind)',
        'note' => 'Note (valgfri)',
        'start_date' => 'Startdato',
        'expense' => 'Udgift',
        'income' => 'Indtægt',
        'cadence' => 'Interval',
        'cadence_weekly' => 'Ugentligt',
        'cadence_monthly' => 'Månedligt',
        'cadence_quarterly' => 'Kvartalsvis',
        'cadence_yearly' => 'Årligt',
        'series' => 'Serie',
        'new_amount' => 'Nyt beløb',
        'new_next_date' => 'Ny næste dato',
        'scope' => 'Omfang',
        'scope_legend' => 'Hvilke hændelser skal flyttes',
        'scope_next' => 'Kun den næste hændelse',
        'scope_all' => 'Alle efterfølgende hændelser',
    ],

    'whatif' => [
        'trigger' => 'Test scenarie',
        'menu_aria' => 'Test scenarie for :name',
        'model_cancellation' => 'Simulér opsigelse',
        'model_amount_change' => 'Simulér beløbsændring…',
        'amount_dialog_aria' => 'Simulér beløbsændring for :name',
        'current_amount' => 'Nuværende beløb',
        'new_amount' => 'Nyt beløb',
    ],

    'series_name_fallback' => 'serie',

    'template' => [
        'cancel' => 'Opsig :name',
        'change_amount' => 'Ændr beløbet for :name',
    ],

    'summary' => [
        'cancel' => 'Opsig :name',
        'series_fallback' => 'serie #:id',
        'one_off' => ':amount :currency den :date',
        'recurring' => ':amount :currency :cadence fra :date',
        'change_amount' => ':name: nyt beløb :amount',
        'shift' => ':name: flyt :scope til :date',
        'scope_all' => 'alle efterfølgende',
        'scope_next' => 'næste',
    ],

    'toast' => [
        'created' => 'Scenariet ":name" er oprettet.',
        'deleted' => 'Scenariet er slettet.',
        'renamed' => 'Scenariet har fået nyt navn.',
        'mutation_added' => 'Ændringen er tilføjet.',
        'mutation_updated' => 'Ændringen er opdateret.',
        'mutation_removed' => 'Ændringen er fjernet.',
    ],

    'errors' => [
        'name_empty' => 'Scenarienavnet må ikke være tomt.',
        'name_too_long' => 'Scenarienavnet må højst være på :max tegn.|Scenarienavnet må højst være på :max tegn.',
        'name_taken' => 'Der findes allerede et scenarie med det navn.',
        'date_out_of_range' => 'Datoen ligger uden for enhver prognosehorisont — fra i dag til :days dag frem — så scenariet ville ikke ændre noget.|Datoen ligger uden for enhver prognosehorisont — fra i dag til :days dage frem — så scenariet ville ikke ændre noget.',
        'pick_kind_first' => 'Vælg først en type ændring.',
        'amount_positive' => 'Beløbet skal være et positivt tal.',
        'scenario_gone' => 'Dette scenarie findes ikke længere — det blev slettet et andet sted. Vælg et andet scenarie, eller lav et nyt.',
        'mutation_gone' => 'Denne ændring findes ikke længere — den blev fjernet et andet sted. Luk editoren, og tilføj den igen, hvis du stadig vil have den.',
    ],
];
