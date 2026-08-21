<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Scenarioredigering — :name',
    'rename_aria' => 'Gi scenarioet nytt navn',
    'save' => 'Lagre',
    'save_changes' => 'Lagre endringer',
    'cancel' => 'Avbryt',
    'rename' => 'Gi nytt navn',
    'confirm_delete' => 'Bekreft sletting',
    'delete_scenario' => 'Slett scenario',
    'delete_confirm' => 'Slette dette scenarioet?',

    'mutations_count' => 'Endringer (:count)',
    'no_mutations' => 'Ingen endringer ennå. Legg til én nedenfor for å se hvordan dette scenarioet står seg mot baselinjen din.',
    'editing' => 'Redigerer — :kind',
    'edit' => 'Rediger',
    'remove' => 'Fjern',

    'add_mutation' => '+ Legg til endring',
    'add_to_scenario' => 'Legg til i scenarioet',
    'pick_kind' => 'Velg en type endring:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Si opp en serie',
            'desc' => 'Fjern alle forventede hendelser i en godkjent serie.',
        ],
        'add_one_off' => [
            'title' => 'Legg til en engangsutgift eller engangsinntekt',
            'desc' => 'En enkelt hypotetisk hendelse på en bestemt dato.',
        ],
        'add_recurring' => [
            'title' => 'Legg til en gjentakende serie',
            'desc' => 'Et hypotetisk nytt abonnement eller en ny inntektskilde.',
        ],
        'change_series_amount' => [
            'title' => 'Endre beløpet i en serie',
            'desc' => 'Simuler en prisøkning eller et prisfall i en eksisterende serie.',
        ],
        'shift_series_date' => [
            'title' => 'Flytt datoen i en serie',
            'desc' => 'Flytt den neste eller alle etterfølgende hendelser frem.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Serie som skal sies opp',
        'pick_series' => '— velg en serie —',
        'date' => 'Dato',
        'amount' => 'Beløp',
        'currency' => 'Valuta',
        'direction' => 'Retning',
        'expense_long' => 'Utgift (penger ut)',
        'income_long' => 'Inntekt (penger inn)',
        'note' => 'Notat (valgfritt)',
        'start_date' => 'Startdato',
        'expense' => 'Utgift',
        'income' => 'Inntekt',
        'cadence' => 'Intervall',
        'cadence_weekly' => 'Ukentlig',
        'cadence_monthly' => 'Månedlig',
        'cadence_quarterly' => 'Kvartalsvis',
        'cadence_yearly' => 'Årlig',
        'series' => 'Serie',
        'new_amount' => 'Nytt beløp',
        'new_next_date' => 'Ny neste dato',
        'scope' => 'Omfang',
        'scope_legend' => 'Hvilke hendelser som skal flyttes',
        'scope_next' => 'Bare den neste hendelsen',
        'scope_all' => 'Alle etterfølgende hendelser',
    ],

    'whatif' => [
        'trigger' => 'Test scenario',
        'menu_aria' => 'Test scenario for :name',
        'model_cancellation' => 'Simuler oppsigelse',
        'model_amount_change' => 'Simuler beløpsendring…',
        'amount_dialog_aria' => 'Simuler beløpsendring for :name',
        'current_amount' => 'Nåværende beløp',
        'new_amount' => 'Nytt beløp',
    ],

    'series_name_fallback' => 'serie',

    'summary' => [
        'cancel' => 'Si opp :name',
        'series_fallback' => 'serie #:id',
        'one_off' => ':amount :currency den :date',
        'recurring' => ':amount :currency :cadence fra :date',
        'change_amount' => ':name: nytt beløp :amount',
        'shift' => ':name: flytt :scope til :date',
        'scope_all' => 'alle etterfølgende',
        'scope_next' => 'neste',
    ],

    'toast' => [
        'created' => 'Scenarioet ":name" er opprettet.',
        'deleted' => 'Scenarioet er slettet.',
        'renamed' => 'Scenarioet har fått nytt navn.',
        'mutation_added' => 'Endringen er lagt til.',
        'mutation_updated' => 'Endringen er oppdatert.',
        'mutation_removed' => 'Endringen er fjernet. Angre',
    ],

    'errors' => [
        'name_empty' => 'Scenarionavnet kan ikke være tomt.',
        'name_too_long' => 'Scenarionavnet kan være på høyst :max tegn.',
        'name_taken' => 'Det finnes allerede et scenario med det navnet.',
        'pick_kind_first' => 'Velg først en type endring.',
        'amount_positive' => 'Beløpet må være et positivt tall.',
    ],
];
