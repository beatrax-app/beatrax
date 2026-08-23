<?php

declare(strict_types=1);

return [
    'heading' => 'Prognose',
    'page_title' => 'Prognose',
    'subtitle' => 'Waar je saldo naartoe gaat — over de komende 30 tot 365 dagen.',
    'adjust_buffers' => 'Buffers aanpassen',

    'empty_heading' => 'Nog geen prognosegegevens',
    'empty_body' => 'Koppel een rekening of keur een terugkerende reeks goed om je verwachte saldo voor de komende weken te zien.',
    'empty_start' => 'Begin met',
    'empty_import_link' => 'een afschrift importeren',
    'empty_or' => 'of',
    'empty_recurring_link' => 'terugkerende patronen bekijken',

    'account_tablist' => 'Rekening',
    'all_accounts' => 'Alle rekeningen',

    'horizon_label' => 'Prognosehorizon',
    'n_days' => ':days dag|:days dagen',

    'view_by_funder' => 'Weergeven per financier',
    'view_by_funder_hint' => 'Voeg keten-herleide reeksen samen op de rekening die ze daadwerkelijk betaalt.',

    'scenario_group' => 'Scenario',
    'baseline' => 'Basislijn',
    'scenario_word' => 'Scenario',
    'new_scenario' => '+ Nieuw scenario',
    'scenario_name_placeholder' => 'Scenarionaam',
    'new_scenario_aria' => 'Naam nieuw scenario',
    'create_scenario' => 'Scenario aanmaken',
    'cancel' => 'Annuleren',

    'aggregate_subtitle' => 'Gecombineerd saldo over al je rekeningen, geprojecteerd over de komende :days dag.|Gecombineerd saldo over al je rekeningen, geprojecteerd over de komende :days dagen.',

    'today' => 'vandaag',
    'on_day' => 'op dag',

    'edit_buffer_aria' => 'Minimale buffer voor :name bewerken',
    'buffer_not_set' => 'Buffer: niet ingesteld',
    'buffer_set' => 'Buffer: :amount',

    'shortfall' => 'Tekort begint :date — :amount onder je buffer van :buffer',

    'compared_against_baseline' => 'Vergeleken met de basislijn hierboven',

    'scenario_editor_aria' => 'Scenario-editor',
    'series_confidence' => 'Betrouwbaarheid reeks',
    'no_series_contribute' => 'Nog geen reeksen dragen bij aan de prognose van deze rekening.',

    'net_diff' => 'Nettoverschil',
    'net_diff_section_aria' => 'Nettoverschil tussen basislijn en scenario op horizondagen 30 / 60 / 90',
    'net_diff_delta_aria' => 'Nettoverschil op dag :day: :value, scenario is :state',
    'better_than_baseline' => 'beter dan de basislijn',
    'worse_than_baseline' => 'slechter dan de basislijn',
    'equal_to_baseline' => 'gelijk aan de basislijn',
    'at_day' => 'op dag :day',

    'updating' => 'Bijwerken',
    'chart_noscript' => 'Grafiek vereist JavaScript. Bereik beslaat :days dag.|Grafiek vereist JavaScript. Bereik beslaat :days dagen.',
    'total_balance' => 'Totaalsaldo',

    'per_month_suffix' => '/mnd',
    'confidence_chip_aria' => ':name, betrouwbaarheid :confidence — projectiebereik is :percent procent van de puntschatting',

    'highlights_title' => 'Prognose-highlights',
    'highlights_shortfall_aria' => ':count actief tekortvenster in de komende :days dagen|:count actieve tekortvensters in de komende :days dagen',
    'on_date_suffix' => ' op :date',
    'shortfall_window' => ':count actief tekortvenster|:count actieve tekortvensters',
    'lowest_in_30_label' => 'Laagste in 30 dagen',
    'next_ics' => 'Volgende ICS-afwikkeling: :amount op :date',
];
