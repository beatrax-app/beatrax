<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Uređivač scenarija — :name',
    'rename_aria' => 'Preimenuj scenario',
    'save' => 'Sačuvaj',
    'save_changes' => 'Sačuvaj izmene',
    'cancel' => 'Otkaži',
    'rename' => 'Preimenuj',
    'confirm_delete' => 'Potvrdi brisanje',
    'delete_scenario' => 'Obriši scenario',
    'delete_confirm' => 'Obrisati ovaj scenario?',

    'mutations_count' => 'Izmene (:count)',
    'no_mutations' => 'Još nema izmena. Dodaj jednu ispod da vidiš kako se ovaj scenario poredi sa tvojom polaznom prognozom.',
    'editing' => 'Uređivanje — :kind',
    'edit' => 'Izmeni',
    'remove' => 'Ukloni',

    'add_mutation' => '+ Dodaj izmenu',
    'add_to_scenario' => 'Dodaj u scenario',
    'pick_kind' => 'Izaberi vrstu izmene:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Otkaži seriju',
            'desc' => 'Izostavi svako predviđeno pojavljivanje odobrene serije.',
        ],
        'add_one_off' => [
            'title' => 'Dodaj jednokratno zaduženje ili odobrenje',
            'desc' => 'Jedan hipotetički događaj na određeni datum.',
        ],
        'add_recurring' => [
            'title' => 'Dodaj ponavljajuću seriju',
            'desc' => 'Hipotetička nova pretplata ili izvor prihoda.',
        ],
        'change_series_amount' => [
            'title' => 'Promeni iznos serije',
            'desc' => 'Modeluj poskupljenje ili pojeftinjenje postojeće serije.',
        ],
        'shift_series_date' => [
            'title' => 'Pomeri datum serije',
            'desc' => 'Pomeri sledeće ili sva naredna pojavljivanja na drugi datum.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Serija za otkazivanje',
        'pick_series' => '— izaberi seriju —',
        'date' => 'Datum',
        'amount' => 'Iznos',
        'currency' => 'Valuta',
        'direction' => 'Smer',
        'expense_long' => 'Trošak (odliv)',
        'income_long' => 'Prihod (priliv)',
        'note' => 'Beleška (opciono)',
        'start_date' => 'Datum početka',
        'expense' => 'Trošak',
        'income' => 'Prihod',
        'cadence' => 'Učestalost',
        'cadence_weekly' => 'Nedeljno',
        'cadence_monthly' => 'Mesečno',
        'cadence_quarterly' => 'Tromesečno',
        'cadence_yearly' => 'Godišnje',
        'series' => 'Serija',
        'new_amount' => 'Novi iznos',
        'new_next_date' => 'Novi sledeći datum',
        'scope' => 'Obim',
        'scope_legend' => 'Koja pojavljivanja pomeriti',
        'scope_next' => 'Samo sledeće pojavljivanje',
        'scope_all' => 'Sva naredna pojavljivanja',
    ],

    'whatif' => [
        'trigger' => 'Modeluj „šta ako”',
        'menu_aria' => 'Modeluj „šta ako” za :name',
        'model_cancellation' => 'Modeluj otkazivanje',
        'model_amount_change' => 'Modeluj promenu iznosa…',
        'amount_dialog_aria' => 'Modeluj promenu iznosa za :name',
        'current_amount' => 'Trenutni iznos',
        'new_amount' => 'Novi iznos',
    ],

    'series_name_fallback' => 'serija',

    'template' => [
        'cancel' => 'Otkaži :name',
        'change_amount' => 'Promeni iznos za :name',
    ],

    'summary' => [
        'cancel' => 'Otkaži :name',
        'series_fallback' => 'serija br. :id',
        'one_off' => ':amount :currency dana :date',
        'recurring' => ':amount :currency :cadence od :date',
        'change_amount' => ':name: novi iznos :amount',
        'shift' => ':name: pomak :scope na :date',
        'scope_all' => 'svih narednih',
        'scope_next' => 'sledećeg',
    ],

    'toast' => [
        'created' => 'Scenario „:name” je napravljen.',
        'deleted' => 'Scenario je obrisan.',
        'renamed' => 'Scenario je preimenovan.',
        'mutation_added' => 'Izmena je dodata.',
        'mutation_updated' => 'Izmena je ažurirana.',
        'mutation_removed' => 'Izmena je uklonjena.',
    ],

    'errors' => [
        'name_empty' => 'Naziv scenarija ne može biti prazan.',
        'name_too_long' => 'Naziv scenarija sme da ima najviše :max znak.|Naziv scenarija sme da ima najviše :max znaka.|Naziv scenarija sme da ima najviše :max znakova.',
        'name_taken' => 'Scenario sa tim nazivom već postoji.',
        'date_out_of_range' => 'Taj datum je izvan svakog horizonta prognoze — od danas do :days dana unapred — pa scenario ne bi ništa promenio.|Taj datum je izvan svakog horizonta prognoze — od danas do :days dana unapred — pa scenario ne bi ništa promenio.|Taj datum je izvan svakog horizonta prognoze — od danas do :days dana unapred — pa scenario ne bi ništa promenio.',
        'pick_kind_first' => 'Prvo izaberi vrstu izmene.',
        'amount_positive' => 'Iznos mora biti pozitivan broj.',
        'scenario_gone' => 'Ovaj scenario više ne postoji — obrisan je drugde. Izaberi drugi scenario ili napravi novi.',
        'mutation_gone' => 'Ova izmena više ne postoji — uklonjena je drugde. Zatvori uređivač i dodaj je ponovo ako je još želiš.',
    ],
];
