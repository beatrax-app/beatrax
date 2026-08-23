<?php

declare(strict_types=1);

return [
    'heading' => 'Прогноз',
    'page_title' => 'Прогноз',
    'subtitle' => 'Куди рухається твій баланс — на наступні від 30 до 365 днів.',
    'adjust_buffers' => 'Налаштувати буфери',

    'empty_heading' => 'Даних для прогнозу ще немає',
    'empty_body' => 'Підключи рахунок або затверди регулярну серію, щоб побачити прогнозований баланс на найближчі тижні.',
    'empty_start' => 'Почни з того, щоб',
    'empty_import_link' => 'імпортувати виписку',
    'empty_or' => 'або',
    'empty_recurring_link' => 'переглянути регулярні патерни',

    'account_tablist' => 'Рахунок',
    'all_accounts' => 'Усі рахунки',

    'horizon_label' => 'Горизонт прогнозу',
    'n_days' => ':days день|:days дні|:days днів',

    'view_by_funder' => 'Показати за джерелом фінансування',
    'view_by_funder_hint' => 'Згортає серії, розв’язані через ланцюги, на рахунок, який їх фактично оплачує.',

    'scenario_group' => 'Сценарій',
    'baseline' => 'Базовий',
    'scenario_word' => 'Сценарій',
    'new_scenario' => '+ Новий сценарій',
    'scenario_name_placeholder' => 'Назва сценарію',
    'new_scenario_aria' => 'Назва нового сценарію',
    'create_scenario' => 'Створити сценарій',
    'cancel' => 'Скасувати',

    'aggregate_subtitle' => 'Сукупний баланс за всіма рахунками, спрогнозований на наступний :days день.|Сукупний баланс за всіма рахунками, спрогнозований на наступні :days дні.|Сукупний баланс за всіма рахунками, спрогнозований на наступні :days днів.',

    'today' => 'сьогодні',
    'on_day' => 'на день',

    'edit_buffer_aria' => 'Редагувати мінімальний буфер — :name',
    'buffer_not_set' => 'Буфер: не встановлено',
    'buffer_set' => 'Буфер: :amount',

    'shortfall' => 'Дефіцит починається :date — :amount нижче твого буфера (:buffer)',

    'compared_against_baseline' => 'Порівняно з базовим вище',

    'scenario_editor_aria' => 'Редактор сценарію',
    'series_confidence' => 'Впевненість серії',
    'no_series_contribute' => 'Жодна серія поки не впливає на прогноз цього рахунку.',

    'net_diff' => 'Чиста різниця',
    'net_diff_section_aria' => 'Чиста різниця між базовим і сценарієм на горизонтах 30 / 60 / 90 днів',
    'net_diff_delta_aria' => 'Чиста різниця на день :day: :value, сценарій :state',
    'better_than_baseline' => 'кращий за базовий',
    'worse_than_baseline' => 'гірший за базовий',
    'equal_to_baseline' => 'дорівнює базовому',
    'at_day' => 'на день :day',

    'updating' => 'Оновлення',
    'chart_noscript' => 'Для графіка потрібен JavaScript. Діапазон охоплює :days день.|Для графіка потрібен JavaScript. Діапазон охоплює :days дні.|Для графіка потрібен JavaScript. Діапазон охоплює :days днів.',
    'total_balance' => 'Загальний баланс',

    'per_month_suffix' => '/міс.',
    'confidence_chip_aria' => ':name, впевненість :confidence — діапазон прогнозу становить :percent відсотків від точкової оцінки',

    'highlights_title' => 'Головне з прогнозу',
    'highlights_shortfall_aria' => ':count активне вікно дефіциту в наступні :days днів|:count активні вікна дефіциту в наступні :days днів|:count активних вікон дефіциту в наступні :days днів',
    'on_date_suffix' => ' (:date)',
    'shortfall_window' => ':count активне вікно дефіциту|:count активні вікна дефіциту|:count активних вікон дефіциту',
    'lowest_in_30_label' => 'Найнижчий за 30 днів',
    'next_ics' => 'Наступне врегулювання ICS: :amount — :date',
];
