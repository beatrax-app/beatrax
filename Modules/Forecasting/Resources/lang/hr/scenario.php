<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Uređivač scenarija — :name',
    'rename_aria' => 'Preimenuj scenarij',
    'save' => 'Spremi',
    'save_changes' => 'Spremi promjene',
    'cancel' => 'Odustani',
    'rename' => 'Preimenuj',
    'confirm_delete' => 'Potvrdi brisanje',
    'delete_scenario' => 'Izbriši scenarij',
    'delete_confirm' => 'Izbrisati ovaj scenarij?',

    'mutations_count' => 'Izmjene (:count)',
    'no_mutations' => 'Još nema izmjena. Dodaj jednu ispod da vidiš kako se ovaj scenarij uspoređuje s tvojom polaznom prognozom.',
    'editing' => 'Uređivanje — :kind',
    'edit' => 'Uredi',
    'remove' => 'Ukloni',

    'add_mutation' => '+ Dodaj izmjenu',
    'add_to_scenario' => 'Dodaj u scenarij',
    'pick_kind' => 'Odaberi vrstu izmjene:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Otkaži seriju',
            'desc' => 'Izostavi svako predviđeno pojavljivanje odobrene serije.',
        ],
        'add_one_off' => [
            'title' => 'Dodaj jednokratno terećenje ili odobrenje',
            'desc' => 'Jedan hipotetski događaj na određeni datum.',
        ],
        'add_recurring' => [
            'title' => 'Dodaj ponavljajuću seriju',
            'desc' => 'Hipotetska nova pretplata ili izvor prihoda.',
        ],
        'change_series_amount' => [
            'title' => 'Promijeni iznos serije',
            'desc' => 'Modeliraj poskupljenje ili pojeftinjenje postojeće serije.',
        ],
        'shift_series_date' => [
            'title' => 'Pomakni datum serije',
            'desc' => 'Premjesti sljedeće ili sva daljnja pojavljivanja na drugi datum.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Serija za otkazivanje',
        'pick_series' => '— odaberi seriju —',
        'date' => 'Datum',
        'amount' => 'Iznos',
        'currency' => 'Valuta',
        'direction' => 'Smjer',
        'expense_long' => 'Trošak (odljev)',
        'income_long' => 'Prihod (priljev)',
        'note' => 'Bilješka (neobavezno)',
        'start_date' => 'Datum početka',
        'expense' => 'Trošak',
        'income' => 'Prihod',
        'cadence' => 'Učestalost',
        'cadence_weekly' => 'Tjedno',
        'cadence_monthly' => 'Mjesečno',
        'cadence_quarterly' => 'Tromjesečno',
        'cadence_yearly' => 'Godišnje',
        'series' => 'Serija',
        'new_amount' => 'Novi iznos',
        'new_next_date' => 'Novi sljedeći datum',
        'scope' => 'Opseg',
        'scope_legend' => 'Koja pojavljivanja pomaknuti',
        'scope_next' => 'Samo sljedeće pojavljivanje',
        'scope_all' => 'Sva daljnja pojavljivanja',
    ],

    'whatif' => [
        'trigger' => 'Modeliraj „što ako”',
        'menu_aria' => 'Modeliraj „što ako” za :name',
        'model_cancellation' => 'Modeliraj otkazivanje',
        'model_amount_change' => 'Modeliraj promjenu iznosa…',
        'amount_dialog_aria' => 'Modeliraj promjenu iznosa za :name',
        'current_amount' => 'Trenutačni iznos',
        'new_amount' => 'Novi iznos',
    ],

    'series_name_fallback' => 'serija',

    'template' => [
        'cancel' => 'Otkaži :name',
        'change_amount' => 'Promijeni iznos za :name',
    ],

    'summary' => [
        'cancel' => 'Otkaži :name',
        'series_fallback' => 'serija br. :id',
        'one_off' => ':amount :currency dana :date',
        'recurring' => ':amount :currency :cadence od :date',
        'change_amount' => ':name: novi iznos :amount',
        'shift' => ':name: pomak :scope na :date',
        'scope_all' => 'svih daljnjih',
        'scope_next' => 'sljedećeg',
    ],

    'toast' => [
        'created' => 'Scenarij „:name” je stvoren.',
        'deleted' => 'Scenarij je izbrisan.',
        'renamed' => 'Scenarij je preimenovan.',
        'mutation_added' => 'Izmjena je dodana.',
        'mutation_updated' => 'Izmjena je ažurirana.',
        'mutation_removed' => 'Izmjena je uklonjena.',
    ],

    'errors' => [
        'name_empty' => 'Naziv scenarija ne može biti prazan.',
        'name_too_long' => 'Naziv scenarija smije imati najviše :max znak.|Naziv scenarija smije imati najviše :max znaka.|Naziv scenarija smije imati najviše :max znakova.',
        'name_taken' => 'Scenarij s tim nazivom već postoji.',
        'date_out_of_range' => 'Taj je datum izvan svakog horizonta prognoze — od danas do :days dana unaprijed — pa scenarij ne bi ništa promijenio.|Taj je datum izvan svakog horizonta prognoze — od danas do :days dana unaprijed — pa scenarij ne bi ništa promijenio.|Taj je datum izvan svakog horizonta prognoze — od danas do :days dana unaprijed — pa scenarij ne bi ništa promijenio.',
        'pick_kind_first' => 'Prvo odaberi vrstu izmjene.',
        'amount_positive' => 'Iznos mora biti pozitivan broj.',
        'scenario_gone' => 'Ovaj scenarij više ne postoji — izbrisan je drugdje. Odaberi drugi scenarij ili napravi novi.',
        'mutation_gone' => 'Ova promjena više ne postoji — uklonjena je drugdje. Zatvori uređivač i dodaj je ponovno ako je još želiš.',
    ],
];
