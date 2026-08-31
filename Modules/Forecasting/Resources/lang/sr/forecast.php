<?php

declare(strict_types=1);

return [
    'heading' => 'Prognoza',
    'page_title' => 'Prognoza',
    'subtitle' => 'Kuda ide tvoje stanje — u narednih 30 do 365 dana.',
    'adjust_buffers' => 'Podesi rezerve',

    'empty_heading' => 'Još nema podataka za prognozu',
    'empty_body' => 'Poveži račun ili odobri ponavljajuću seriju da vidiš projektovano stanje u narednim nedeljama.',
    'empty_start' => 'Počni tako što ćeš',
    'empty_import_link' => 'uvesti izvod',
    'empty_or' => 'ili',
    'empty_recurring_link' => 'pregledati ponavljajuće obrasce',

    'account_tablist' => 'Račun',
    'all_accounts' => 'Svi računi',

    'horizon_label' => 'Horizont prognoze',
    'n_days' => ':days dan|:days dana|:days dana',

    'view_by_funder' => 'Prikaži po platiocu',
    'view_by_funder_hint' => 'Sažmi serije razrešene lancem na račun koji ih zapravo plaća.',

    'scenario_group' => 'Scenario',
    'baseline' => 'Polazni scenario',
    'scenario_word' => 'Scenario',
    'new_scenario' => '+ Novi scenario',
    'scenario_name_placeholder' => 'Naziv scenarija',
    'new_scenario_aria' => 'Naziv novog scenarija',
    'create_scenario' => 'Napravi scenario',
    'cancel' => 'Otkaži',

    'aggregate_subtitle' => 'Ukupno stanje svih računa, projektovano za naredni :days dan.|Ukupno stanje svih računa, projektovano za naredna :days dana.|Ukupno stanje svih računa, projektovano za narednih :days dana.',

    'today' => 'danas',
    'on_day' => 'na dan',

    'edit_buffer_aria' => 'Izmeni minimalnu rezervu za :name',
    'buffer_not_set' => 'Rezerva: nije postavljena',
    'buffer_set' => 'Rezerva: :amount',

    'shortfall' => 'Manjak počinje :date — :amount ispod tvoje rezerve od :buffer',

    'compared_against_baseline' => 'Upoređeno sa polaznim scenariom iznad',

    'run_failed' => 'Ovu projekciju nije bilo moguće izračunati. Linija ispod prikazuje samo ono što je već proknjiženo.',

    'scenario_editor_aria' => 'Uređivač scenarija',
    'series_confidence' => 'Pouzdanost serije',
    'no_series_contribute' => 'Nijedna serija još ne utiče na prognozu ovog računa.',

    'net_diff' => 'Neto razlika',

    'net_diff_unknown' => 'Još nije izračunato za ovaj horizont.',
    'net_diff_section_aria' => 'Neto razlika između polaznog scenarija i scenarija na horizontu od 30 / 60 / 90 dana',
    'net_diff_delta_aria' => 'Neto razlika na dan :day: :value, scenario je :state',
    'better_than_baseline' => 'bolji od polaznog scenarija',
    'worse_than_baseline' => 'lošiji od polaznog scenarija',
    'equal_to_baseline' => 'jednak polaznom scenariju',
    'at_day' => 'na dan :day',

    'updating' => 'Ažuriranje',
    'chart_noscript' => 'Grafikon zahteva JavaScript. Raspon obuhvata :days dan.|Grafikon zahteva JavaScript. Raspon obuhvata :days dana.|Grafikon zahteva JavaScript. Raspon obuhvata :days dana.',
    'total_balance' => 'Ukupno stanje',
    'projection_range' => 'Raspon prognoze',
    'point_estimate' => 'Tačkasta procena',

    'per_month_suffix' => '/mes.',
    'confidence_chip_aria' => ':name, pouzdanost :confidence — raspon prognoze je :percent procenata tačkaste procene',

    'highlights_title' => 'Izdvojeno iz prognoze',
    'highlights_shortfall_aria' => ':count aktivan period manjka u narednih :days dana|:count aktivna perioda manjka u narednih :days dana|:count aktivnih perioda manjka u narednih :days dana',
    'on_date_suffix' => ' na dan :date',
    'shortfall_window' => ':count aktivan period manjka|:count aktivna perioda manjka|:count aktivnih perioda manjka',
    'lowest_in_30_label' => 'Najniže u 30 dana',
    'next_ics' => 'Sledeće ICS namirenje: :amount na dan :date',
    'ics_overdue' => 'ICS namirenje je dospelo: :amount, rok je bio :date',

    'stale_run' => 'Projekcija od :date — od tada nije osvežena.',

    'confidence' => [
        'high' => 'Visoka',
        'medium' => 'Srednja',
        'low' => 'Niska',
    ],

    'errors' => [
        'amount_required' => 'Iznos je obavezan.',
        'amount_decimals' => 'Iznos mora biti broj sa najviše :decimals decimalom.|Iznos mora biti broj sa najviše :decimals decimale.|Iznos mora biti broj sa najviše :decimals decimala.',
        'amount_whole' => 'Iznos mora biti ceo broj — ova valuta nema manju jedinicu.',
        'amount_non_negative' => 'Iznos mora biti nula ili pozitivan.',
        'amount_non_zero' => 'Iznos ne sme biti nula.',
        'field_required' => 'Polje :field je obavezno.',
    ],
];
