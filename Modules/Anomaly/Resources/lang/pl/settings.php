<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Czułość alertów',
    'sensitivity_help' => 'Jak łatwo Beatrax uznaje obciążenie za nietypowe dla tego sprzedawcy lub kategorii, od 1 do 100. Wyższa wartość oznacza więcej alertów.',

    'min_amount_label' => 'Minimalna kwota obciążenia',
    'min_amount_help' => 'Pomijaj anomalie przy obciążeniach poniżej tej kwoty. Zapisywane w centach (:symbol) — 1000 oznacza :example.',

    'save' => 'Zapisz ustawienia anomalii',
    'saved' => 'Zapisano.',

    'suppression' => [
        'summary' => 'Reguły wyciszania',
        'empty' => 'Brak reguł wyciszania. Gdy oznaczysz obciążenie jako oczekiwane, pojawi się tutaj reguła.',
        'remove' => 'Usuń',
        'remove_aria' => 'Usuń regułę wyciszania',
        'removed_toast' => 'Reguła usunięta',
    ],

    'unknown_merchant' => 'Nieznany sprzedawca',

    'detectors' => [
        'large' => 'Duże obciążenie',
        'first_time' => 'Pierwszy raz',
        'duplicate' => 'Duplikat',
    ],

    'errors' => [
        'sensitivity_range' => 'Czułość musi mieścić się w zakresie od 1 do 100.',
        'min_amount_negative' => 'Minimalna kwota obciążenia nie może być ujemna.',
    ],
];
