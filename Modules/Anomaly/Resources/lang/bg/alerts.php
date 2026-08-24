<?php

declare(strict_types=1);

return [
    'unknown_merchant' => 'Неизвестен търговец',

    'reasons' => [
        'large' => 'Голямо плащане',
        'first_time' => 'За първи път',
        'duplicate' => 'Дубликат',
    ],

    'reason_aria' => [
        'first_time' => 'Причина: търговец за първи път',
        'duplicate' => 'Причина: дублирано плащане',
        'generic' => 'Причина: :label',
    ],

    'baseline_to_actual' => 'база :baseline → реално: :actual',
    'detected' => 'открито на :date',
    'sensitivity' => 'чувствителност :percent of 100',

    'actions_summary' => 'Действия',

    'chips' => [
        'acknowledge' => 'Потвърди',
        'acknowledge_aria' => 'Потвърди сигнала за аномалия при :name',
        'snooze' => 'Отложи',
        'snooze_options' => 'Опции за отлагане',
        'snooze_1w' => '1 седмица',
        'snooze_1m' => '1 месец',
        'snooze_3m' => '3 месеца',
        'mark_expected' => 'Отбележи като очаквано',
        'mark_expected_aria' => 'Отбележи сигнала за аномалия при :name като очакван',
        'dismiss' => 'Отхвърли',
        'dismiss_aria' => 'Отхвърли сигнала за аномалия при :name',
        'unknown_merchant' => 'неизвестен търговец',
    ],
];
