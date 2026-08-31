<?php

declare(strict_types=1);

return [
    'heading' => 'Prognoze',
    'page_title' => 'Prognoze',
    'subtitle' => 'Uz kurieni virzās jūsu atlikums — nākamajās 30 līdz 365 dienās.',
    'adjust_buffers' => 'Pielāgot rezerves',

    'empty_heading' => 'Vēl nav prognozes datu',
    'empty_body' => 'Pievienojiet kontu vai apstipriniet regulāro maksājumu sēriju, lai redzētu prognozēto atlikumu tuvākajās nedēļās.',
    'empty_start' => 'Sāciet ar to, ka',
    'empty_import_link' => 'importējat konta izrakstu',
    'empty_or' => 'vai',
    'empty_recurring_link' => 'pārskatāt regulāros modeļus',

    'account_tablist' => 'Konts',
    'all_accounts' => 'Visi konti',

    'horizon_label' => 'Prognozes horizonts',
    'n_days' => ':days dienu|:days diena|:days dienas',

    'view_by_funder' => 'Skatīt pēc finansētāja',
    'view_by_funder_hint' => 'Apvienot ķēdēs atrisinātās sērijas uz to kontu, kas tās patiesībā apmaksā.',

    'scenario_group' => 'Scenārijs',
    'baseline' => 'Bāzes līnija',
    'scenario_word' => 'Scenārijs',
    'new_scenario' => '+ Jauns scenārijs',
    'scenario_name_placeholder' => 'Scenārija nosaukums',
    'new_scenario_aria' => 'Jaunā scenārija nosaukums',
    'create_scenario' => 'Izveidot scenāriju',
    'cancel' => 'Atcelt',

    'aggregate_subtitle' => 'Kopējais atlikums visos kontos, prognozēts nākamajām :days dienām.|Kopējais atlikums visos kontos, prognozēts nākamajai :days dienai.|Kopējais atlikums visos kontos, prognozēts nākamajām :days dienām.',

    'today' => 'šodien',
    'on_day' => 'dienā',

    'edit_buffer_aria' => 'Rediģēt minimālo rezervi kontam :name',
    'buffer_not_set' => 'Rezerve: nav iestatīta',
    'buffer_set' => 'Rezerve: :amount',

    'shortfall' => 'Iztrūkums sākas :date — :amount zem jūsu :buffer rezerves',

    'compared_against_baseline' => 'Salīdzināts ar bāzes līniju augšpusē',

    'run_failed' => 'Šo prognozi neizdevās aprēķināt. Zemāk redzamā līnija rāda tikai to, kas jau ir iegrāmatots.',

    'scenario_editor_aria' => 'Scenāriju redaktors',
    'series_confidence' => 'Sērijas ticamība',
    'no_series_contribute' => 'Šī konta prognozē pagaidām neietilpst neviena sērija.',

    'net_diff' => 'Neto starpība',

    'net_diff_unknown' => 'Šim periodam vēl nav aprēķināts.',
    'net_diff_section_aria' => 'Neto starpība starp bāzes līniju un scenāriju 30 / 60 / 90 dienu horizontā',
    'net_diff_delta_aria' => 'Neto starpība :day. dienā: :value, scenārijs ir :state',
    'better_than_baseline' => 'labāks par bāzes līniju',
    'worse_than_baseline' => 'sliktāks par bāzes līniju',
    'equal_to_baseline' => 'vienāds ar bāzes līniju',
    'at_day' => ':day. dienā',

    'updating' => 'Atjaunina',
    'chart_noscript' => 'Diagrammai nepieciešams JavaScript. Diapazons aptver :days dienu.|Diagrammai nepieciešams JavaScript. Diapazons aptver :days dienu.|Diagrammai nepieciešams JavaScript. Diapazons aptver :days dienas.',
    'total_balance' => 'Kopējais atlikums',
    'projection_range' => 'Prognozes diapazons',
    'point_estimate' => 'Punkta novērtējums',

    'per_month_suffix' => '/mēn.',
    'confidence_chip_aria' => ':name, ticamība :confidence — prognozes diapazons ir :percent procenti no punkta novērtējuma',

    'highlights_title' => 'Prognozes svarīgākais',
    'highlights_shortfall_aria' => ':count aktīvu iztrūkuma periodu nākamajās :days dienās|:count aktīvs iztrūkuma periods nākamajās :days dienās|:count aktīvi iztrūkuma periodi nākamajās :days dienās',
    'on_date_suffix' => ' — :date',
    'shortfall_window' => ':count aktīvu iztrūkuma periodu|:count aktīvs iztrūkuma periods|:count aktīvi iztrūkuma periodi',
    'lowest_in_30_label' => 'Zemākais 30 dienās',
    'next_ics' => 'Nākamais ICS norēķins: :amount — :date',
    'ics_overdue' => 'ICS norēķins nokavēts: :amount, termiņš bija :date',

    'stale_run' => 'Prognoze no :date — kopš tā laika nav atjaunināta.',

    'confidence' => [
        'high' => 'Augsta',
        'medium' => 'Vidēja',
        'low' => 'Zema',
    ],

    'errors' => [
        'amount_required' => 'Summa ir obligāta.',
        'amount_decimals' => 'Summai jābūt skaitlim ar ne vairāk kā :decimals decimāldaļām.|Summai jābūt skaitlim ar ne vairāk kā :decimals decimāldaļu.|Summai jābūt skaitlim ar ne vairāk kā :decimals decimāldaļām.',
        'amount_whole' => 'Summai jābūt veselam skaitlim — šai valūtai nav mazākas vienības.',
        'amount_non_negative' => 'Summai jābūt nullei vai pozitīvai.',
        'amount_non_zero' => 'Summa nedrīkst būt nulle.',
        'field_required' => 'Lauks :field ir obligāts.',
    ],
];
