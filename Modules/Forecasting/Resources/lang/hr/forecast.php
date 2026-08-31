<?php

declare(strict_types=1);

return [
    'heading' => 'Prognoza',
    'page_title' => 'Prognoza',
    'subtitle' => 'Kamo ide tvoje stanje — kroz sljedećih 30 do 365 dana.',
    'adjust_buffers' => 'Prilagodi rezerve',

    'empty_heading' => 'Još nema podataka za prognozu',
    'empty_body' => 'Poveži račun ili odobri ponavljajuću seriju da vidiš predviđeno stanje kroz nadolazeće tjedne.',
    'empty_start' => 'Počni tako da',
    'empty_import_link' => 'uvezeš izvod',
    'empty_or' => 'ili',
    'empty_recurring_link' => 'pregledaš ponavljajuće obrasce',

    'account_tablist' => 'Račun',
    'all_accounts' => 'Svi računi',

    'horizon_label' => 'Horizont prognoze',
    'n_days' => ':days dan|:days dana|:days dana',

    'view_by_funder' => 'Prikaži po platitelju',
    'view_by_funder_hint' => 'Sažmi serije razriješene lancem na račun koji ih zapravo plaća.',

    'scenario_group' => 'Scenarij',
    'baseline' => 'Polazni scenarij',
    'scenario_word' => 'Scenarij',
    'new_scenario' => '+ Novi scenarij',
    'scenario_name_placeholder' => 'Naziv scenarija',
    'new_scenario_aria' => 'Naziv novog scenarija',
    'create_scenario' => 'Stvori scenarij',
    'cancel' => 'Odustani',

    'aggregate_subtitle' => 'Ukupno stanje svih računa, predviđeno za sljedeći :days dan.|Ukupno stanje svih računa, predviđeno za sljedeća :days dana.|Ukupno stanje svih računa, predviđeno za sljedećih :days dana.',

    'today' => 'danas',
    'on_day' => 'na dan',

    'edit_buffer_aria' => 'Uredi minimalnu rezervu za :name',
    'buffer_not_set' => 'Rezerva: nije postavljena',
    'buffer_set' => 'Rezerva: :amount',

    'shortfall' => 'Manjak počinje :date — :amount ispod tvoje rezerve od :buffer',

    'compared_against_baseline' => 'Uspoređeno s polaznim scenarijem iznad',

    'run_failed' => 'Ovu projekciju nije bilo moguće izračunati. Crta ispod prikazuje samo ono što je već proknjiženo.',

    'scenario_editor_aria' => 'Uređivač scenarija',
    'series_confidence' => 'Pouzdanost serije',
    'no_series_contribute' => 'Nijedna serija još ne utječe na prognozu ovog računa.',

    'net_diff' => 'Neto razlika',

    'net_diff_unknown' => 'Još nije izračunato za ovaj horizont.',
    'net_diff_section_aria' => 'Neto razlika između polaznog scenarija i scenarija na horizontu od 30 / 60 / 90 dana',
    'net_diff_delta_aria' => 'Neto razlika na dan :day: :value, scenarij je :state',
    'better_than_baseline' => 'bolji od polaznog scenarija',
    'worse_than_baseline' => 'lošiji od polaznog scenarija',
    'equal_to_baseline' => 'jednak polaznom scenariju',
    'at_day' => 'na dan :day',

    'updating' => 'Ažuriranje',
    'chart_noscript' => 'Grafikon zahtijeva JavaScript. Raspon obuhvaća :days dan.|Grafikon zahtijeva JavaScript. Raspon obuhvaća :days dana.|Grafikon zahtijeva JavaScript. Raspon obuhvaća :days dana.',
    'total_balance' => 'Ukupno stanje',
    'projection_range' => 'Raspon prognoze',
    'point_estimate' => 'Točkovna procjena',

    'per_month_suffix' => '/mj.',
    'confidence_chip_aria' => ':name, pouzdanost :confidence — raspon prognoze je :percent posto točkovne procjene',

    'highlights_title' => 'Istaknuto iz prognoze',
    'highlights_shortfall_aria' => ':count aktivno razdoblje manjka u sljedećih :days dana|:count aktivna razdoblja manjka u sljedećih :days dana|:count aktivnih razdoblja manjka u sljedećih :days dana',
    'on_date_suffix' => ' na dan :date',
    'shortfall_window' => ':count aktivno razdoblje manjka|:count aktivna razdoblja manjka|:count aktivnih razdoblja manjka',
    'lowest_in_30_label' => 'Najniže u 30 dana',
    'next_ics' => 'Sljedeće ICS namirenje: :amount na dan :date',
    'ics_overdue' => 'ICS namirenje je dospjelo: :amount, rok je bio :date',

    'stale_run' => 'Projekcija od :date — od tada nije osvježena.',

    'confidence' => [
        'high' => 'Visoka',
        'medium' => 'Srednja',
        'low' => 'Niska',
    ],

    'errors' => [
        'amount_required' => 'Iznos je obavezan.',
        'amount_decimals' => 'Iznos mora biti broj s najviše :decimals decimalom.|Iznos mora biti broj s najviše :decimals decimale.|Iznos mora biti broj s najviše :decimals decimala.',
        'amount_whole' => 'Iznos mora biti cijeli broj — ova valuta nema manju jedinicu.',
        'amount_non_negative' => 'Iznos mora biti nula ili pozitivan.',
        'amount_non_zero' => 'Iznos ne smije biti nula.',
        'field_required' => 'Polje :field je obavezno.',
    ],
];
