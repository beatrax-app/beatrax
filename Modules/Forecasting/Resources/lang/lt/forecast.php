<?php

declare(strict_types=1);

return [
    'heading' => 'Prognozė',
    'page_title' => 'Prognozė',
    'subtitle' => 'Kur juda tavo likutis — per artimiausias 30–365 dienas.',
    'adjust_buffers' => 'Keisti atsargas',

    'empty_heading' => 'Prognozės duomenų dar nėra',
    'empty_body' => 'Prijunk sąskaitą arba patvirtink pasikartojančių mokėjimų seriją, kad matytum prognozuojamą likutį artimiausioms savaitėms.',
    'empty_start' => 'Pradėk',
    'empty_import_link' => 'importuodamas sąskaitos išrašą',
    'empty_or' => 'arba',
    'empty_recurring_link' => 'peržiūrėdamas pasikartojančius modelius',

    'account_tablist' => 'Sąskaita',
    'all_accounts' => 'Visos sąskaitos',

    'horizon_label' => 'Prognozės horizontas',
    'n_days' => ':days d.|:days d.|:days d.',

    'view_by_funder' => 'Rodyti pagal finansuotoją',
    'view_by_funder_hint' => 'Sujungti grandine susietas serijas į tą sąskaitą, kuri iš tikrųjų už jas moka.',

    'scenario_group' => 'Scenarijus',
    'baseline' => 'Bazinis',
    'scenario_word' => 'Scenarijus',
    'new_scenario' => '+ Naujas scenarijus',
    'scenario_name_placeholder' => 'Scenarijaus pavadinimas',
    'new_scenario_aria' => 'Naujo scenarijaus pavadinimas',
    'create_scenario' => 'Sukurti scenarijų',
    'cancel' => 'Atšaukti',

    'aggregate_subtitle' => 'Bendras visų sąskaitų likutis, prognozuojamas artimiausiai :days dienai.|Bendras visų sąskaitų likutis, prognozuojamas artimiausioms :days dienoms.|Bendras visų sąskaitų likutis, prognozuojamas artimiausioms :days dienoms.',

    'today' => 'šiandien',
    'on_day' => 'dieną',

    'edit_buffer_aria' => 'Redaguoti mažiausią :name atsargą',
    'buffer_not_set' => 'Atsarga: nenustatyta',
    'buffer_set' => 'Atsarga: :amount',

    'shortfall' => 'Trūkumas prasideda :date — :amount žemiau tavo :buffer atsargos',

    'compared_against_baseline' => 'Palyginta su baziniu variantu viršuje',

    'run_failed' => 'Šios prognozės apskaičiuoti nepavyko. Žemiau esanti linija rodo tik tai, kas jau užregistruota.',

    'scenario_editor_aria' => 'Scenarijaus redaktorius',
    'series_confidence' => 'Serijos patikimumas',
    'no_series_contribute' => 'Šios sąskaitos prognozei kol kas neprisideda nė viena serija.',

    'net_diff' => 'Grynasis skirtumas',

    'net_diff_unknown' => 'Šiam laikotarpiui dar neapskaičiuota.',
    'net_diff_section_aria' => 'Grynasis skirtumas tarp bazinio varianto ir scenarijaus 30 / 60 / 90 horizonto dienomis',
    'net_diff_delta_aria' => 'Grynasis skirtumas :day dieną: :value, scenarijus yra :state',
    'better_than_baseline' => 'geresnis nei bazinis',
    'worse_than_baseline' => 'blogesnis nei bazinis',
    'equal_to_baseline' => 'lygus baziniam',
    'at_day' => ':day dieną',

    'updating' => 'Atnaujinama',
    'chart_noscript' => 'Diagramai reikia JavaScript. Intervalas apima :days dieną.|Diagramai reikia JavaScript. Intervalas apima :days dienas.|Diagramai reikia JavaScript. Intervalas apima :days dienų.',
    'total_balance' => 'Bendras likutis',
    'projection_range' => 'Prognozės intervalas',
    'point_estimate' => 'Taškinis įvertis',

    'per_month_suffix' => '/mėn.',
    'confidence_chip_aria' => ':name, patikimumas :confidence — prognozės intervalas sudaro :percent procentų taškinio įverčio',

    'highlights_title' => 'Prognozės akcentai',
    'highlights_shortfall_aria' => ':count aktyvus trūkumo laikotarpis per artimiausias :days dienas|:count aktyvūs trūkumo laikotarpiai per artimiausias :days dienas|:count aktyvių trūkumo laikotarpių per artimiausias :days dienas',
    'on_date_suffix' => ' :date',
    'shortfall_window' => ':count aktyvus trūkumo laikotarpis|:count aktyvūs trūkumo laikotarpiai|:count aktyvių trūkumo laikotarpių',
    'lowest_in_30_label' => 'Mažiausias per 30 dienų',
    'next_ics' => 'Kitas ICS atsiskaitymas: :amount :date',
    'ics_overdue' => 'ICS atsiskaitymas pradelstas: :amount, terminas buvo :date',

    'stale_run' => 'Prognozė nuo :date — nuo tada neatnaujinta.',

    'confidence' => [
        'high' => 'Aukštas',
        'medium' => 'Vidutinis',
        'low' => 'Žemas',
    ],

    'errors' => [
        'amount_required' => 'Suma yra privaloma.',
        'amount_decimals' => 'Suma turi būti skaičius su ne daugiau kaip :decimals dešimtaine dalimi.|Suma turi būti skaičius su ne daugiau kaip :decimals dešimtainėmis dalimis.|Suma turi būti skaičius su ne daugiau kaip :decimals dešimtainių dalių.',
        'amount_whole' => 'Suma turi būti sveikasis skaičius — ši valiuta neturi mažesnio vieneto.',
        'amount_non_negative' => 'Suma turi būti nulis arba teigiama.',
        'amount_non_zero' => 'Suma negali būti nulis.',
        'field_required' => 'Laukas :field yra privalomas.',
    ],
];
