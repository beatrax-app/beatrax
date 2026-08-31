<?php

declare(strict_types=1);

return [
    'unknown_merchant' => 'Nieznany sprzedawca',

    'reasons' => [
        'large' => 'Duże obciążenie',
        'first_time' => 'Pierwszy raz',
        'duplicate' => 'Duplikat',
    ],

    'reason_aria' => [
        'first_time' => 'Powód: sprzedawca po raz pierwszy',
        'duplicate' => 'Powód: zduplikowane obciążenie',
        'generic' => 'Powód: :label',
    ],

    'baseline_to_actual' => 'poziom bazowy :baseline → faktycznie: :actual',
    'charged' => 'obciążenie :actual',
    'detected' => 'wykryto :date',
    'sensitivity' => 'czułość :percent na 100',

    'actions_summary' => 'Akcje',

    'chips' => [
        'acknowledge' => 'Potwierdź',
        'acknowledge_aria' => 'Potwierdź alert o anomalii — :name',
        'snooze' => 'Odłóż',
        'snooze_options' => 'Opcje odłożenia',
        'snooze_1w' => '1 tydzień',
        'snooze_1m' => '1 miesiąc',
        'snooze_3m' => '3 miesiące',
        'mark_expected' => 'Oznacz jako oczekiwane',
        'mark_expected_aria' => 'Oznacz alert o anomalii jako oczekiwany — :name',
        'dismiss' => 'Odrzuć',
        'dismiss_aria' => 'Odrzuć alert o anomalii — :name',
        'unknown_merchant' => 'nieznany sprzedawca',
    ],
];
