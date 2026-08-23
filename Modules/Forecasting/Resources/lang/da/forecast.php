<?php

declare(strict_types=1);

return [
    'heading' => 'Prognose',
    'page_title' => 'Prognose',
    'subtitle' => 'Hvor din saldo er på vej hen — over de næste 30 til 365 dage.',
    'adjust_buffers' => 'Justér buffere',

    'empty_heading' => 'Ingen prognosedata endnu',
    'empty_body' => 'Forbind en konto eller godkend en tilbagevendende serie for at se din forventede saldo i de kommende uger.',
    'empty_start' => 'Start med at',
    'empty_import_link' => 'importere et kontoudtog',
    'empty_or' => 'eller',
    'empty_recurring_link' => 'gennemgå tilbagevendende mønstre',

    'account_tablist' => 'Konto',
    'all_accounts' => 'Alle konti',

    'horizon_label' => 'Prognosehorisont',
    'n_days' => ':days dag|:days dage',

    'view_by_funder' => 'Vis pr. betalende konto',
    'view_by_funder_hint' => 'Saml serier, der er løst via kæder, på den konto, der reelt betaler dem.',

    'scenario_group' => 'Scenarie',
    'baseline' => 'Basislinje',
    'scenario_word' => 'Scenarie',
    'new_scenario' => '+ Nyt scenarie',
    'scenario_name_placeholder' => 'Scenarienavn',
    'new_scenario_aria' => 'Navn på nyt scenarie',
    'create_scenario' => 'Opret scenarie',
    'cancel' => 'Annullér',

    'aggregate_subtitle' => 'Samlet saldo på tværs af alle konti, fremskrevet over den næste :days dag.|Samlet saldo på tværs af alle konti, fremskrevet over de næste :days dage.',

    'today' => 'i dag',
    'on_day' => 'på dag',

    'edit_buffer_aria' => 'Redigér mindste buffer for :name',
    'buffer_not_set' => 'Buffer: ikke angivet',
    'buffer_set' => 'Buffer: :amount',

    'shortfall' => 'Underskuddet starter :date — :amount under din buffer på :buffer',

    'compared_against_baseline' => 'Sammenlignet med basislinjen ovenfor',

    'scenario_editor_aria' => 'Scenarieeditor',
    'series_confidence' => 'Seriens pålidelighed',
    'no_series_contribute' => 'Ingen serier bidrager til prognosen for denne konto endnu.',

    'net_diff' => 'Nettoforskel',
    'net_diff_section_aria' => 'Nettoforskel mellem basislinje og scenarie ved horisontdagene 30 / 60 / 90',
    'net_diff_delta_aria' => 'Nettoforskel på dag :day: :value, scenariet er :state',
    'better_than_baseline' => 'bedre end basislinjen',
    'worse_than_baseline' => 'dårligere end basislinjen',
    'equal_to_baseline' => 'lig med basislinjen',
    'at_day' => 'på dag :day',

    'updating' => 'Opdaterer',
    'chart_noscript' => 'Diagrammet kræver JavaScript. Intervallet dækker :days dag.|Diagrammet kræver JavaScript. Intervallet dækker :days dage.',
    'total_balance' => 'Samlet saldo',

    'per_month_suffix' => '/md.',
    'confidence_chip_aria' => ':name, pålidelighed :confidence — prognoseintervallet er :percent procent af punktestimatet',

    'highlights_title' => 'Prognosen kort fortalt',
    'highlights_shortfall_aria' => ':count aktiv underskudsperiode i de næste :days dage|:count aktive underskudsperioder i de næste :days dage',
    'on_date_suffix' => ' den :date',
    'shortfall_window' => ':count aktiv underskudsperiode|:count aktive underskudsperioder',
    'lowest_in_30_label' => 'Laveste på 30 dage',
    'next_ics' => 'Næste ICS-afregning: :amount den :date',
];
