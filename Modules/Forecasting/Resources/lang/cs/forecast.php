<?php

declare(strict_types=1);

return [
    'heading' => 'Předpověď',
    'page_title' => 'Předpověď',
    'subtitle' => 'Kam míří tvůj zůstatek — na dalších 30 až 365 dní.',
    'adjust_buffers' => 'Upravit rezervy',

    'empty_heading' => 'Zatím žádná data pro předpověď',
    'empty_body' => 'Připoj účet nebo schval opakovanou řadu, ať uvidíš předpokládaný zůstatek na nadcházející týdny.',
    'empty_start' => 'Začni',
    'empty_import_link' => 'importem výpisu z účtu',
    'empty_or' => 'nebo',
    'empty_recurring_link' => 'kontrolou opakovaných vzorců',

    'account_tablist' => 'Účet',
    'all_accounts' => 'Všechny účty',

    'horizon_label' => 'Horizont předpovědi',
    'n_days' => ':days den|:days dny|:days dní',

    'view_by_funder' => 'Zobrazit podle plátce',
    'view_by_funder_hint' => 'Sloučit řady vyřešené řetězcem pod účet, který je doopravdy platí.',

    'scenario_group' => 'Scénář',
    'baseline' => 'Základ',
    'scenario_word' => 'Scénář',
    'new_scenario' => '+ Nový scénář',
    'scenario_name_placeholder' => 'Název scénáře',
    'new_scenario_aria' => 'Název nového scénáře',
    'create_scenario' => 'Vytvořit scénář',
    'cancel' => 'Zrušit',

    'aggregate_subtitle' => 'Souhrnný zůstatek na všech účtech, promítnutý na další :days den.|Souhrnný zůstatek na všech účtech, promítnutý na další :days dny.|Souhrnný zůstatek na všech účtech, promítnutý na dalších :days dní.',

    'today' => 'dnes',
    'on_day' => 'v den',

    'edit_buffer_aria' => 'Upravit minimální rezervu pro: :name',
    'buffer_not_set' => 'Rezerva: nenastavena',
    'buffer_set' => 'Rezerva: :amount',

    'shortfall' => 'Schodek začíná :date — :amount pod rezervou :buffer',

    'compared_against_baseline' => 'Porovnáno se základem výše',

    'scenario_editor_aria' => 'Editor scénáře',
    'series_confidence' => 'Spolehlivost řady',
    'no_series_contribute' => 'Do předpovědi tohoto účtu zatím žádná řada nepřispívá.',

    'net_diff' => 'Rozdíl netto',
    'net_diff_section_aria' => 'Rozdíl netto mezi základem a scénářem v horizontu 30 / 60 / 90 dní',
    'net_diff_delta_aria' => 'Rozdíl netto v den :day: :value, scénář je :state',
    'better_than_baseline' => 'lepší než základ',
    'worse_than_baseline' => 'horší než základ',
    'equal_to_baseline' => 'stejný jako základ',
    'at_day' => 'v den :day',

    'updating' => 'Aktualizuje se',
    'chart_noscript' => 'Graf vyžaduje JavaScript. Rozsah pokrývá :days den.|Graf vyžaduje JavaScript. Rozsah pokrývá :days dny.|Graf vyžaduje JavaScript. Rozsah pokrývá :days dní.',
    'total_balance' => 'Celkový zůstatek',

    'per_month_suffix' => '/měs.',
    'confidence_chip_aria' => ':name, spolehlivost :confidence — rozpětí předpovědi je :percent procent bodového odhadu',

    'highlights_title' => 'Hlavní body předpovědi',
    'highlights_shortfall_aria' => ':count aktivní okno se schodkem v příštích :days dnech|:count aktivní okna se schodkem v příštích :days dnech|:count aktivních oken se schodkem v příštích :days dnech',
    'on_date_suffix' => ' dne :date',
    'shortfall_window' => ':count aktivní okno se schodkem|:count aktivní okna se schodkem|:count aktivních oken se schodkem',
    'lowest_in_30_label' => 'Nejnižší za 30 dní',
    'next_ics' => 'Příští vypořádání ICS: :amount dne :date',
];
