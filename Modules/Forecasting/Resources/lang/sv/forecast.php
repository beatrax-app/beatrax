<?php

declare(strict_types=1);

return [
    'heading' => 'Prognos',
    'page_title' => 'Prognos',
    'subtitle' => 'Vart ditt saldo är på väg — under de kommande 30 till 365 dagarna.',
    'adjust_buffers' => 'Justera buffertar',

    'empty_heading' => 'Inga prognosdata än',
    'empty_body' => 'Anslut ett konto eller godkänn en återkommande serie för att se ditt prognostiserade saldo under de kommande veckorna.',
    'empty_start' => 'Börja med att',
    'empty_import_link' => 'importera ett kontoutdrag',
    'empty_or' => 'eller',
    'empty_recurring_link' => 'granska återkommande mönster',

    'account_tablist' => 'Konto',
    'all_accounts' => 'Alla konton',

    'horizon_label' => 'Prognoshorisont',
    'n_days' => ':days dag|:days dagar',

    'view_by_funder' => 'Visa per betalande konto',
    'view_by_funder_hint' => 'Slå ihop serier som lösts via kedjor på det konto som faktiskt betalar dem.',

    'scenario_group' => 'Scenario',
    'baseline' => 'Baslinje',
    'scenario_word' => 'Scenario',
    'new_scenario' => '+ Nytt scenario',
    'scenario_name_placeholder' => 'Scenarionamn',
    'new_scenario_aria' => 'Namn på nytt scenario',
    'create_scenario' => 'Skapa scenario',
    'cancel' => 'Avbryt',

    'aggregate_subtitle' => 'Samlat saldo för alla konton, prognostiserat för den kommande :days dagen.|Samlat saldo för alla konton, prognostiserat för de kommande :days dagarna.',

    'today' => 'idag',
    'on_day' => 'på dag',

    'edit_buffer_aria' => 'Redigera minsta buffert för :name',
    'buffer_not_set' => 'Buffert: inte angiven',
    'buffer_set' => 'Buffert: :amount',

    'shortfall' => 'Underskottet börjar :date — :amount under din buffert på :buffer',

    'compared_against_baseline' => 'Jämfört med baslinjen ovan',

    'run_failed' => 'Den här prognosen kunde inte beräknas. Linjen nedan visar bara det som redan är bokfört.',

    'scenario_editor_aria' => 'Scenarioredigerare',
    'series_confidence' => 'Seriens tillförlitlighet',
    'no_series_contribute' => 'Inga serier bidrar till prognosen för det här kontot än.',

    'net_diff' => 'Nettoskillnad',

    'net_diff_unknown' => 'Ännu inte beräknat för denna horisont.',
    'net_diff_section_aria' => 'Nettoskillnad mellan baslinje och scenario vid horisontdagarna 30 / 60 / 90',
    'net_diff_delta_aria' => 'Nettoskillnad dag :day: :value, scenariot är :state',
    'better_than_baseline' => 'bättre än baslinjen',
    'worse_than_baseline' => 'sämre än baslinjen',
    'equal_to_baseline' => 'lika med baslinjen',
    'at_day' => 'på dag :day',

    'updating' => 'Uppdaterar',
    'chart_noscript' => 'Diagrammet kräver JavaScript. Intervallet omfattar :days dag.|Diagrammet kräver JavaScript. Intervallet omfattar :days dagar.',
    'total_balance' => 'Totalt saldo',
    'projection_range' => 'Prognosintervall',
    'point_estimate' => 'Punktskattning',

    'per_month_suffix' => '/mån',
    'confidence_chip_aria' => ':name, tillförlitlighet :confidence — prognosintervallet är :percent procent av punktskattningen',

    'highlights_title' => 'Prognosen i korthet',
    'highlights_shortfall_aria' => ':count aktiv underskottsperiod under de kommande :days dagarna|:count aktiva underskottsperioder under de kommande :days dagarna',
    'on_date_suffix' => ' den :date',
    'shortfall_window' => ':count aktiv underskottsperiod|:count aktiva underskottsperioder',
    'lowest_in_30_label' => 'Lägst på 30 dagar',
    'next_ics' => 'Nästa ICS-avräkning: :amount den :date',
    'ics_overdue' => 'ICS-avräkningen har förfallit: :amount, förföll :date',

    'stale_run' => 'Prognos från :date — inte uppdaterad sedan dess.',

    'confidence' => [
        'high' => 'Hög',
        'medium' => 'Medel',
        'low' => 'Låg',
    ],

    'errors' => [
        'amount_required' => 'Belopp krävs.',
        'amount_decimals' => 'Belopp måste vara ett tal med högst :decimals decimal.|Belopp måste vara ett tal med högst :decimals decimaler.',
        'amount_whole' => 'Belopp måste vara ett heltal — den här valutan har ingen mindre enhet.',
        'amount_non_negative' => 'Belopp måste vara noll eller positivt.',
        'amount_non_zero' => 'Belopp får inte vara noll.',
        'field_required' => 'Fältet :field krävs.',
    ],
];
