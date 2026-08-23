<?php

declare(strict_types=1);

return [
    'heading' => 'Прогноза',
    'page_title' => 'Прогноза',
    'subtitle' => 'Накъде върви салдото ти — през следващите 30 до 365 дни.',
    'adjust_buffers' => 'Настрой буферите',

    'empty_heading' => 'Още няма данни за прогноза',
    'empty_body' => 'Свържи сметка или одобри повтаряща се поредица, за да видиш прогнозното си салдо през следващите седмици.',
    'empty_start' => 'Започни, като',
    'empty_import_link' => 'импортираш извлечение',
    'empty_or' => 'или',
    'empty_recurring_link' => 'прегледаш повтарящите се модели',

    'account_tablist' => 'Сметка',
    'all_accounts' => 'Всички сметки',

    'horizon_label' => 'Хоризонт на прогнозата',
    'n_days' => ':days ден|:days дни',

    'view_by_funder' => 'Изглед по финансираща сметка',
    'view_by_funder_hint' => 'Групира поредиците, разрешени чрез верига, към сметката, която действително ги плаща.',

    'scenario_group' => 'Сценарий',
    'baseline' => 'Базов сценарий',
    'scenario_word' => 'Сценарий',
    'new_scenario' => '+ Нов сценарий',
    'scenario_name_placeholder' => 'Име на сценария',
    'new_scenario_aria' => 'Име на новия сценарий',
    'create_scenario' => 'Създай сценарий',
    'cancel' => 'Отказ',

    'aggregate_subtitle' => 'Общото салдо по всички сметки, прогнозирано за следващия :days ден.|Общото салдо по всички сметки, прогнозирано за следващите :days дни.',

    'today' => 'днес',
    'on_day' => 'на ден',

    'edit_buffer_aria' => 'Редактирай минималния буфер за :name',
    'buffer_not_set' => 'Буфер: не е зададен',
    'buffer_set' => 'Буфер: :amount',

    'shortfall' => 'Недостигът започва на :date — :amount под буфера ти от :buffer',

    'compared_against_baseline' => 'Сравнено с базовия сценарий по-горе',

    'scenario_editor_aria' => 'Редактор на сценарии',
    'series_confidence' => 'Увереност в поредицата',
    'no_series_contribute' => 'Още никоя поредица не участва в прогнозата за тази сметка.',

    'net_diff' => 'Нетна разлика',
    'net_diff_section_aria' => 'Нетна разлика между базовия сценарий и сценария при хоризонт 30 / 60 / 90 дни',
    'net_diff_delta_aria' => 'Нетна разлика на ден :day: :value, сценарият е :state',
    'better_than_baseline' => 'по-добър от базовия сценарий',
    'worse_than_baseline' => 'по-лош от базовия сценарий',
    'equal_to_baseline' => 'равен на базовия сценарий',
    'at_day' => 'на ден :day',

    'updating' => 'Обновяване',
    'chart_noscript' => 'Графиката изисква JavaScript. Обхватът покрива :days ден.|Графиката изисква JavaScript. Обхватът покрива :days дни.',
    'total_balance' => 'Общо салдо',

    'per_month_suffix' => '/мес.',
    'confidence_chip_aria' => ':name, увереност :confidence — прогнозният интервал е :percent процента от точковата оценка',

    'highlights_title' => 'Акценти от прогнозата',
    'highlights_shortfall_aria' => ':count активен период с недостиг през следващите :days дни|:count активни периода с недостиг през следващите :days дни',
    'on_date_suffix' => ' на :date',
    'shortfall_window' => '{0} няма активни периоди с недостиг|[1,1] :count активен период с недостиг|[2,*] :count активни периода с недостиг',
    'lowest_in_30_label' => 'Най-ниско за 30 дни',
    'next_ics' => 'Следващо уреждане по ICS: :amount на :date',
];
