<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Skenaarioeditori — :name',
    'rename_aria' => 'Nimeä skenaario uudelleen',
    'save' => 'Tallenna',
    'save_changes' => 'Tallenna muutokset',
    'cancel' => 'Peruuta',
    'rename' => 'Nimeä uudelleen',
    'confirm_delete' => 'Vahvista poisto',
    'delete_scenario' => 'Poista skenaario',
    'delete_confirm' => 'Poistetaanko tämä skenaario?',

    'mutations_count' => 'Muutokset (:count)',
    'no_mutations' => 'Ei vielä muutoksia. Lisää yksi alta, niin näet miten tämä skenaario vertautuu perustasoosi.',
    'editing' => 'Muokataan — :kind',
    'edit' => 'Muokkaa',
    'remove' => 'Poista',

    'add_mutation' => '+ Lisää muutos',
    'add_to_scenario' => 'Lisää skenaarioon',
    'pick_kind' => 'Valitse muutoksen tyyppi:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Irtisano sarja',
            'desc' => 'Poista hyväksytyn sarjan kaikki ennustetut esiintymät.',
        ],
        'add_one_off' => [
            'title' => 'Lisää kertaluonteinen veloitus tai hyvitys',
            'desc' => 'Yksittäinen kuvitteellinen tapahtuma tiettynä päivänä.',
        ],
        'add_recurring' => [
            'title' => 'Lisää toistuva sarja',
            'desc' => 'Kuvitteellinen uusi tilaus tai tulovirta.',
        ],
        'change_series_amount' => [
            'title' => 'Muuta sarjan summaa',
            'desc' => 'Mallinna hinnannousu tai -lasku olemassa olevassa sarjassa.',
        ],
        'shift_series_date' => [
            'title' => 'Siirrä sarjan päivämäärää',
            'desc' => 'Siirrä seuraavaa tai kaikkia myöhempiä esiintymiä eteenpäin.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Irtisanottava sarja',
        'pick_series' => '— valitse sarja —',
        'date' => 'Päivämäärä',
        'amount' => 'Summa',
        'currency' => 'Valuutta',
        'direction' => 'Suunta',
        'expense_long' => 'Meno (rahaa ulos)',
        'income_long' => 'Tulo (rahaa sisään)',
        'note' => 'Muistiinpano (valinnainen)',
        'start_date' => 'Alkupäivä',
        'expense' => 'Meno',
        'income' => 'Tulo',
        'cadence' => 'Maksuväli',
        'cadence_weekly' => 'Viikoittain',
        'cadence_monthly' => 'Kuukausittain',
        'cadence_quarterly' => 'Neljännesvuosittain',
        'cadence_yearly' => 'Vuosittain',
        'series' => 'Sarja',
        'new_amount' => 'Uusi summa',
        'new_next_date' => 'Uusi seuraava päivä',
        'scope' => 'Laajuus',
        'scope_legend' => 'Mitkä esiintymät siirretään',
        'scope_next' => 'Vain seuraava esiintymä',
        'scope_all' => 'Kaikki myöhemmät esiintymät',
    ],

    'whatif' => [
        'trigger' => 'Mallinna entä jos',
        'menu_aria' => 'Mallinna entä jos kohteelle :name',
        'model_cancellation' => 'Mallinna irtisanominen',
        'model_amount_change' => 'Mallinna summan muutos…',
        'amount_dialog_aria' => 'Mallinna summan muutos kohteelle :name',
        'current_amount' => 'Nykyinen summa',
        'new_amount' => 'Uusi summa',
    ],

    'series_name_fallback' => 'sarja',

    'summary' => [
        'cancel' => 'Irtisano :name',
        'series_fallback' => 'sarja #:id',
        'one_off' => ':amount :currency päivänä :date',
        'recurring' => ':amount :currency :cadence alkaen :date',
        'change_amount' => ':name: uusi summa :amount',
        'shift' => ':name: siirrä :scope päivään :date',
        'scope_all' => 'kaikki myöhemmät',
        'scope_next' => 'seuraava',
    ],

    'toast' => [
        'created' => 'Skenaario ”:name” luotu.',
        'deleted' => 'Skenaario poistettu.',
        'renamed' => 'Skenaario nimetty uudelleen.',
        'mutation_added' => 'Muutos lisätty.',
        'mutation_updated' => 'Muutos päivitetty.',
        'mutation_removed' => 'Muutos poistettu. Kumoa',
    ],

    'errors' => [
        'name_empty' => 'Skenaarion nimi ei voi olla tyhjä.',
        'name_too_long' => 'Skenaarion nimessä saa olla enintään :max merkki.|Skenaarion nimessä saa olla enintään :max merkkiä.',
        'name_taken' => 'Samanniminen skenaario on jo olemassa.',
        'pick_kind_first' => 'Valitse ensin muutoksen tyyppi.',
        'amount_positive' => 'Summan on oltava positiivinen luku.',
    ],
];
