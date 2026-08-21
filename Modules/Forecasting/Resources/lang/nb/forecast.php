<?php

declare(strict_types=1);

return [
    'heading' => 'Prognose',
    'page_title' => 'Prognose',
    'subtitle' => 'Hvor saldoen din er på vei — de neste 30 til 365 dagene.',
    'adjust_buffers' => 'Juster buffere',

    'empty_heading' => 'Ingen prognosedata ennå',
    'empty_body' => 'Koble til en konto eller godkjenn en gjentakende serie for å se den forventede saldoen din i ukene som kommer.',
    'empty_start' => 'Start med å',
    'empty_import_link' => 'importere en kontoutskrift',
    'empty_or' => 'eller',
    'empty_recurring_link' => 'gjennomgå gjentakende mønstre',

    'account_tablist' => 'Konto',
    'all_accounts' => 'Alle kontoer',

    'horizon_label' => 'Prognosehorisont',
    'n_days' => ':days dag|:days dager',

    'view_by_funder' => 'Vis per betalende konto',
    'view_by_funder_hint' => 'Slå sammen serier som er løst via kjeder, på kontoen som faktisk betaler dem.',

    'scenario_group' => 'Scenario',
    'baseline' => 'Baselinje',
    'scenario_word' => 'Scenario',
    'new_scenario' => '+ Nytt scenario',
    'scenario_name_placeholder' => 'Scenarionavn',
    'new_scenario_aria' => 'Navn på nytt scenario',
    'create_scenario' => 'Opprett scenario',
    'cancel' => 'Avbryt',

    'aggregate_subtitle' => 'Samlet saldo på tvers av alle kontoer, fremskrevet over den neste :days dagen.|Samlet saldo på tvers av alle kontoer, fremskrevet over de neste :days dagene.',

    'today' => 'i dag',
    'on_day' => 'på dag',

    'edit_buffer_aria' => 'Rediger minste buffer for :name',
    'buffer_not_set' => 'Buffer: ikke angitt',
    'buffer_set' => 'Buffer: :amount',

    'shortfall' => 'Underskuddet starter :date — :amount under bufferen din på :buffer',

    'compared_against_baseline' => 'Sammenlignet med baselinjen ovenfor',

    'scenario_editor_aria' => 'Scenarioredigering',
    'series_confidence' => 'Seriens pålitelighet',
    'no_series_contribute' => 'Ingen serier bidrar til prognosen for denne kontoen ennå.',

    'net_diff' => 'Nettoforskjell',
    'net_diff_section_aria' => 'Nettoforskjell mellom baselinje og scenario ved horisontdagene 30 / 60 / 90',
    'net_diff_delta_aria' => 'Nettoforskjell på dag :day: :value, scenarioet er :state',
    'better_than_baseline' => 'bedre enn baselinjen',
    'worse_than_baseline' => 'dårligere enn baselinjen',
    'equal_to_baseline' => 'likt baselinjen',
    'at_day' => 'på dag :day',

    'updating' => 'Oppdaterer',
    'chart_noscript' => 'Diagrammet krever JavaScript. Intervallet dekker :days dag.|Diagrammet krever JavaScript. Intervallet dekker :days dager.',
    'total_balance' => 'Samlet saldo',

    'per_month_suffix' => '/mnd.',
    'confidence_chip_aria' => ':name, pålitelighet :confidence — prognoseintervallet er :percent prosent av punktestimatet',

    'highlights_title' => 'Prognosen kort fortalt',
    'highlights_shortfall_aria' => ':count aktiv underskuddsperiode de neste :days dagene|:count aktive underskuddsperioder de neste :days dagene',
    'dips_to' => ':name faller til :amount',
    'on_date_suffix' => ' den :date',
    'shortfall_window' => ':count aktiv underskuddsperiode|:count aktive underskuddsperioder',
    'lowest_in_30' => 'Laveste på 30 dager: :amount',
    'next_ics' => 'Neste ICS-oppgjør: :amount den :date',
];
