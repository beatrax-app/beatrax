<?php

declare(strict_types=1);

return [
    'heading' => 'Previziune',
    'page_title' => 'Previziune',
    'subtitle' => 'Încotro se îndreaptă soldul tău — în următoarele 30 până la 365 de zile.',
    'adjust_buffers' => 'Ajustează rezervele',

    'empty_heading' => 'Nicio dată de previziune deocamdată',
    'empty_body' => 'Conectează un cont sau aprobă o serie recurentă ca să vezi soldul estimat în săptămânile care vin.',
    'empty_start' => 'Începe prin a',
    'empty_import_link' => 'importa un extras de cont',
    'empty_or' => 'sau',
    'empty_recurring_link' => 'verifica tiparele recurente',

    'account_tablist' => 'Cont',
    'all_accounts' => 'Toate conturile',

    'horizon_label' => 'Orizontul previziunii',
    'n_days' => ':days zi|:days zile|:days de zile',

    'view_by_funder' => 'Vezi după finanțator',
    'view_by_funder_hint' => 'Grupează seriile rezolvate prin lanț pe contul care le plătește efectiv.',

    'scenario_group' => 'Scenariu',
    'baseline' => 'Scenariu de bază',
    'scenario_word' => 'Scenariu',
    'new_scenario' => '+ Scenariu nou',
    'scenario_name_placeholder' => 'Numele scenariului',
    'new_scenario_aria' => 'Numele scenariului nou',
    'create_scenario' => 'Creează scenariul',
    'cancel' => 'Anulează',

    'aggregate_subtitle' => 'Soldul combinat al tuturor conturilor, estimat pentru următoarea :days zi.|Soldul combinat al tuturor conturilor, estimat pentru următoarele :days zile.|Soldul combinat al tuturor conturilor, estimat pentru următoarele :days de zile.',

    'today' => 'azi',
    'on_day' => 'în ziua',

    'edit_buffer_aria' => 'Editează rezerva minimă pentru :name',
    'buffer_not_set' => 'Rezervă: nesetată',
    'buffer_set' => 'Rezervă: :amount',

    'shortfall' => 'Deficitul începe pe :date — :amount sub rezerva ta de :buffer',

    'compared_against_baseline' => 'Comparat cu scenariul de bază de mai sus',

    'scenario_editor_aria' => 'Editor de scenarii',
    'series_confidence' => 'Încrederea în serie',
    'no_series_contribute' => 'Nicio serie nu contribuie încă la previziunea acestui cont.',

    'net_diff' => 'Diferență netă',
    'net_diff_section_aria' => 'Diferența netă între scenariul de bază și scenariu la orizontul de 30 / 60 / 90 de zile',
    'net_diff_delta_aria' => 'Diferență netă în ziua :day: :value, scenariul este :state',
    'better_than_baseline' => 'mai bun decât scenariul de bază',
    'worse_than_baseline' => 'mai slab decât scenariul de bază',
    'equal_to_baseline' => 'egal cu scenariul de bază',
    'at_day' => 'în ziua :day',

    'updating' => 'Se actualizează',
    'chart_noscript' => 'Graficul necesită JavaScript. Intervalul acoperă :days zi.|Graficul necesită JavaScript. Intervalul acoperă :days zile.|Graficul necesită JavaScript. Intervalul acoperă :days de zile.',
    'total_balance' => 'Sold total',

    'per_month_suffix' => '/lună',
    'confidence_chip_aria' => ':name, încredere :confidence — intervalul previziunii reprezintă :percent la sută din estimarea punctuală',

    'highlights_title' => 'Repere ale previziunii',
    'highlights_shortfall_aria' => ':count fereastră de deficit activă în următoarele :days de zile|:count ferestre de deficit active în următoarele :days de zile|:count de ferestre de deficit active în următoarele :days de zile',
    'on_date_suffix' => ' pe :date',
    'shortfall_window' => 'o fereastră de deficit activă|:count ferestre de deficit active|:count de ferestre de deficit active',
    'lowest_in_30_label' => 'Minimul în 30 de zile',
    'next_ics' => 'Următoarea decontare ICS: :amount pe :date',
];
