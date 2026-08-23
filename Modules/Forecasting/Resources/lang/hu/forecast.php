<?php

declare(strict_types=1);

return [
    'heading' => 'Előrejelzés',
    'page_title' => 'Előrejelzés',
    'subtitle' => 'Merre tart az egyenleged — a következő 30–365 napban.',
    'adjust_buffers' => 'Tartalékok módosítása',

    'empty_heading' => 'Még nincs előrejelzési adat',
    'empty_body' => 'Csatlakoztass egy számlát, vagy hagyj jóvá egy ismétlődő sorozatot, hogy lásd az előrejelzett egyenleged a következő hetekre.',
    'empty_start' => 'Kezdd ezzel:',
    'empty_import_link' => 'kivonat importálása',
    'empty_or' => 'vagy',
    'empty_recurring_link' => 'ismétlődő minták áttekintése',

    'account_tablist' => 'Számla',
    'all_accounts' => 'Összes számla',

    'horizon_label' => 'Előrejelzési időtáv',
    'n_days' => ':days nap|:days nap',

    'view_by_funder' => 'Fedezet szerinti nézet',
    'view_by_funder_hint' => 'A láncból feloldott sorozatokat arra a számlára vonja össze, amelyik ténylegesen fizeti őket.',

    'scenario_group' => 'Forgatókönyv',
    'baseline' => 'Alapeset',
    'scenario_word' => 'Forgatókönyv',
    'new_scenario' => '+ Új forgatókönyv',
    'scenario_name_placeholder' => 'Forgatókönyv neve',
    'new_scenario_aria' => 'Új forgatókönyv neve',
    'create_scenario' => 'Forgatókönyv létrehozása',
    'cancel' => 'Mégse',

    'aggregate_subtitle' => 'Az összes számla együttes egyenlege, a következő :days napra előrejelezve.|Az összes számla együttes egyenlege, a következő :days napra előrejelezve.',

    'today' => 'ma',
    'on_day' => 'ezen a napon:',

    'edit_buffer_aria' => 'A(z) :name minimális tartalékának szerkesztése',
    'buffer_not_set' => 'Tartalék: nincs beállítva',
    'buffer_set' => 'Tartalék: :amount',

    'shortfall' => 'A hiány ekkor kezdődik: :date — :amount összeggel a(z) :buffer tartalékod alatt',

    'compared_against_baseline' => 'A fenti alapesethez viszonyítva',

    'scenario_editor_aria' => 'Forgatókönyv-szerkesztő',
    'series_confidence' => 'Sorozat megbízhatósága',
    'no_series_contribute' => 'Ehhez a számlához még egyetlen sorozat sem járul hozzá az előrejelzésben.',

    'net_diff' => 'Nettó eltérés',
    'net_diff_section_aria' => 'Nettó eltérés az alapeset és a forgatókönyv között a 30., 60. és 90. napon',
    'net_diff_delta_aria' => 'Nettó eltérés a(z) :day. napon: :value, a forgatókönyv :state',
    'better_than_baseline' => 'jobb az alapesetnél',
    'worse_than_baseline' => 'rosszabb az alapesetnél',
    'equal_to_baseline' => 'megegyezik az alapesettel',
    'at_day' => 'a(z) :day. napon',

    'updating' => 'Frissítés',
    'chart_noscript' => 'A diagramhoz JavaScript szükséges. A tartomány :days napot ölel fel.|A diagramhoz JavaScript szükséges. A tartomány :days napot ölel fel.',
    'total_balance' => 'Teljes egyenleg',

    'per_month_suffix' => '/hó',
    'confidence_chip_aria' => ':name, :confidence megbízhatóság — az előrejelzési tartomány a pontbecslés :percent százaléka',

    'highlights_title' => 'Előrejelzés kiemelt pontjai',
    'highlights_shortfall_aria' => ':count aktív hiányidőszak a következő :days napban|:count aktív hiányidőszak a következő :days napban',
    'on_date_suffix' => ' ekkor: :date',
    'shortfall_window' => '{0} nincs aktív hiányidőszak|[1,1] :count aktív hiányidőszak|[2,*] :count aktív hiányidőszak',
    'lowest_in_30_label' => 'Legalacsonyabb 30 napon belül',
    'next_ics' => 'Következő ICS-elszámolás: :amount ekkor: :date',
];
