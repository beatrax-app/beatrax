<?php

declare(strict_types=1);

return [
    'unknown_merchant' => 'Невідомий продавець',

    'reasons' => [
        'large' => 'Велике списання',
        'first_time' => 'Уперше',
        'duplicate' => 'Дублікат',
    ],

    'reason_aria' => [
        'first_time' => 'Причина: продавець уперше',
        'duplicate' => 'Причина: дубльоване списання',
        'generic' => 'Причина: :label',
    ],

    'baseline_to_actual' => 'базовий рівень :baseline → фактично: :actual',
    'charged' => 'списано :actual',
    'detected' => 'виявлено :date',
    'sensitivity' => 'чутливість :percent зі 100',

    'actions_summary' => 'Дії',

    'chips' => [
        'acknowledge' => 'Підтвердити',
        'acknowledge_aria' => 'Підтвердити сповіщення про аномалію — :name',
        'snooze' => 'Відкласти',
        'snooze_options' => 'Варіанти відкладення',
        'snooze_1w' => '1 тиждень',
        'snooze_1m' => '1 місяць',
        'snooze_3m' => '3 місяці',
        'mark_expected' => 'Позначити як очікуване',
        'mark_expected_aria' => 'Позначити сповіщення про аномалію як очікуване — :name',
        'dismiss' => 'Відхилити',
        'dismiss_aria' => 'Відхилити сповіщення про аномалію — :name',
        'unknown_merchant' => 'невідомий продавець',
    ],
];
