<?php

declare(strict_types=1);

return [
    'heading' => 'Prognoos',
    'page_title' => 'Prognoos',
    'subtitle' => 'Kuhu sinu jääk liigub — järgmise 30 kuni 365 päeva jooksul.',
    'adjust_buffers' => 'Kohanda puhvreid',

    'empty_heading' => 'Prognoosi andmeid veel pole',
    'empty_body' => 'Ühenda konto või kinnita korduvmaksete seeria, et näha oma prognoositavat jääki lähinädalatel.',
    'empty_start' => 'Alusta sellest, et',
    'empty_import_link' => 'impordid kontoväljavõtte',
    'empty_or' => 'või',
    'empty_recurring_link' => 'vaatad korduvad mustrid üle',

    'account_tablist' => 'Konto',
    'all_accounts' => 'Kõik kontod',

    'horizon_label' => 'Prognoosi horisont',
    'n_days' => ':days päev|:days päeva',

    'view_by_funder' => 'Vaata rahastaja järgi',
    'view_by_funder_hint' => 'Koonda ahelaga lahendatud seeriad kontole, mis neid tegelikult maksab.',

    'scenario_group' => 'Stsenaarium',
    'baseline' => 'Baasjoon',
    'scenario_word' => 'Stsenaarium',
    'new_scenario' => '+ Uus stsenaarium',
    'scenario_name_placeholder' => 'Stsenaariumi nimi',
    'new_scenario_aria' => 'Uue stsenaariumi nimi',
    'create_scenario' => 'Loo stsenaarium',
    'cancel' => 'Tühista',

    'aggregate_subtitle' => 'Kõigi kontode ühendatud jääk, prognoositud järgmiseks :days päevaks.|Kõigi kontode ühendatud jääk, prognoositud järgmiseks :days päevaks.',

    'today' => 'täna',
    'on_day' => 'päeval',

    'edit_buffer_aria' => 'Muuda konto :name miinimumpuhvrit',
    'buffer_not_set' => 'Puhver: määramata',
    'buffer_set' => 'Puhver: :amount',

    'shortfall' => 'Puudujääk algab :date — :amount alla sinu puhvri :buffer',

    'compared_against_baseline' => 'Võrreldud ülaloleva baasjoonega',

    'run_failed' => 'Seda prognoosi ei õnnestunud arvutada. Allolev joon näitab ainult seda, mis on juba kirjendatud.',

    'scenario_editor_aria' => 'Stsenaariumi redaktor',
    'series_confidence' => 'Seeria kindlus',
    'no_series_contribute' => 'Ükski seeria ei mõjuta veel selle konto prognoosi.',

    'net_diff' => 'Netovahe',

    'net_diff_unknown' => 'Selle horisondi jaoks veel arvutamata.',
    'net_diff_section_aria' => 'Netovahe baasjoone ja stsenaariumi vahel horisondi päevadel 30 / 60 / 90',
    'net_diff_delta_aria' => 'Netovahe päeval :day: :value, stsenaarium on :state',
    'better_than_baseline' => 'baasjoonest parem',
    'worse_than_baseline' => 'baasjoonest halvem',
    'equal_to_baseline' => 'baasjoonega võrdne',
    'at_day' => 'päeval :day',

    'updating' => 'Uuendan',
    'chart_noscript' => 'Graafik vajab JavaScripti. Vahemik hõlmab :days päeva.|Graafik vajab JavaScripti. Vahemik hõlmab :days päeva.',
    'total_balance' => 'Kogujääk',
    'projection_range' => 'Prognoosi vahemik',
    'point_estimate' => 'Punkthinnang',

    'per_month_suffix' => '/kuus',
    'confidence_chip_aria' => ':name, kindlus :confidence — prognoosi vahemik on :percent protsenti punkthinnangust',

    'highlights_title' => 'Prognoosi tähelepanekud',
    'highlights_shortfall_aria' => ':count aktiivne puudujäägi aken järgmise :days päeva jooksul|:count aktiivset puudujäägi akent järgmise :days päeva jooksul',
    'on_date_suffix' => ' kuupäeval :date',
    'shortfall_window' => ':count aktiivne puudujäägi aken|:count aktiivset puudujäägi akent',
    'lowest_in_30_label' => 'Madalaim 30 päeva jooksul',
    'next_ics' => 'Järgmine ICS arveldus: :amount kuupäeval :date',
    'ics_overdue' => 'ICS arveldus on tähtaja ületanud: :amount, tähtaeg oli :date',

    'stale_run' => 'Prognoos seisuga :date — pole sellest ajast värskendatud.',

    'confidence' => [
        'high' => 'Kõrge',
        'medium' => 'Keskmine',
        'low' => 'Madal',
    ],

    'errors' => [
        'amount_required' => 'Summa on kohustuslik.',
        'amount_decimals' => 'Summa peab olema arv, milles on kuni :decimals kümnendkoht.|Summa peab olema arv, milles on kuni :decimals kümnendkohta.',
        'amount_whole' => 'Summa peab olema täisarv — sellel valuutal pole väiksemat ühikut.',
        'amount_non_negative' => 'Summa peab olema null või positiivne.',
        'amount_non_zero' => 'Summa ei tohi olla null.',
        'field_required' => 'Väli :field on kohustuslik.',
    ],
];
