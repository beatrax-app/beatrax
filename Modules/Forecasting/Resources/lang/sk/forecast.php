<?php

declare(strict_types=1);

return [
    'heading' => 'Prognóza',
    'page_title' => 'Prognóza',
    'subtitle' => 'Kam smeruje tvoj zostatok — na najbližších 30 až 365 dní.',
    'adjust_buffers' => 'Upraviť rezervy',

    'empty_heading' => 'Zatiaľ žiadne údaje na prognózu',
    'empty_body' => 'Pripoj účet alebo schváľ opakovanú sériu a uvidíš predpokladaný zostatok na najbližšie týždne.',
    'empty_start' => 'Začni',
    'empty_import_link' => 'importom výpisu z účtu',
    'empty_or' => 'alebo',
    'empty_recurring_link' => 'kontrolou opakovaných vzorov',

    'account_tablist' => 'Účet',
    'all_accounts' => 'Všetky účty',

    'horizon_label' => 'Horizont prognózy',
    'n_days' => ':days deň|:days dni|:days dní',

    'view_by_funder' => 'Zobraziť podľa platiteľa',
    'view_by_funder_hint' => 'Zlúči série rozpoznané v reťazcoch na účet, ktorý ich naozaj platí.',

    'scenario_group' => 'Scenár',
    'baseline' => 'Východisko',
    'scenario_word' => 'Scenár',
    'new_scenario' => '+ Nový scenár',
    'scenario_name_placeholder' => 'Názov scenára',
    'new_scenario_aria' => 'Názov nového scenára',
    'create_scenario' => 'Vytvoriť scenár',
    'cancel' => 'Zrušiť',

    'aggregate_subtitle' => 'Súhrnný zostatok všetkých účtov, prognóza na najbližší :days deň.|Súhrnný zostatok všetkých účtov, prognóza na najbližšie :days dni.|Súhrnný zostatok všetkých účtov, prognóza na najbližších :days dní.',

    'today' => 'dnes',
    'on_day' => 'v deň',

    'edit_buffer_aria' => 'Upraviť minimálnu rezervu pre: :name',
    'buffer_not_set' => 'Rezerva: nenastavená',
    'buffer_set' => 'Rezerva: :amount',

    'shortfall' => 'Nedostatok sa začína :date — :amount pod rezervou :buffer',

    'compared_against_baseline' => 'Porovnané s východiskom vyššie',

    'scenario_editor_aria' => 'Editor scenára',
    'series_confidence' => 'Spoľahlivosť série',
    'no_series_contribute' => 'Do prognózy tohto účtu zatiaľ nevstupuje žiadna séria.',

    'net_diff' => 'Čistý rozdiel',

    'net_diff_unknown' => 'Pre tento horizont zatiaľ nevypočítané.',
    'net_diff_section_aria' => 'Čistý rozdiel medzi východiskom a scenárom v dňoch horizontu 30 / 60 / 90',
    'net_diff_delta_aria' => 'Čistý rozdiel v deň :day: :value, scenár je :state',
    'better_than_baseline' => 'lepší než východisko',
    'worse_than_baseline' => 'horší než východisko',
    'equal_to_baseline' => 'rovnaký ako východisko',
    'at_day' => 'v deň :day',

    'updating' => 'Aktualizuje sa',
    'chart_noscript' => 'Graf vyžaduje JavaScript. Rozsah pokrýva :days deň.|Graf vyžaduje JavaScript. Rozsah pokrýva :days dni.|Graf vyžaduje JavaScript. Rozsah pokrýva :days dní.',
    'total_balance' => 'Celkový zostatok',

    'per_month_suffix' => '/mes.',
    'confidence_chip_aria' => ':name, spoľahlivosť :confidence — rozsah prognózy je :percent percent bodového odhadu',

    'highlights_title' => 'Hlavné body prognózy',
    'highlights_shortfall_aria' => ':count aktívne okno nedostatku v najbližších :days dňoch|:count aktívne okná nedostatku v najbližších :days dňoch|:count aktívnych okien nedostatku v najbližších :days dňoch',
    'on_date_suffix' => ' dňa :date',
    'shortfall_window' => ':count aktívne okno nedostatku|:count aktívne okná nedostatku|:count aktívnych okien nedostatku',
    'lowest_in_30_label' => 'Najnižšie za 30 dní',
    'next_ics' => 'Ďalšie zúčtovanie ICS: :amount dňa :date',
    'ics_overdue' => 'Zúčtovanie ICS po splatnosti: :amount, splatné :date',
];
