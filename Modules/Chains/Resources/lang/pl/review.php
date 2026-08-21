<?php

declare(strict_types=1);

return [
    'page_title' => 'Przegląd łańcuchów',
    'heading' => 'Przegląd łańcuchów',
    'hint' => ':count wskazówka|:count wskazówki|:count wskazówek',
    'subtitle' => 'Potwierdź lub odrzuć kandydujące powiązania, których mechanizm łańcuchów nie potwierdził automatycznie.',

    'empty_heading' => 'Nie ma nic do przejrzenia',
    'empty_body' => 'Każde powiązanie łańcucha jest potwierdzone albo odrzucone. Nowi kandydaci pojawią się tutaj wraz z kolejnymi importami.',

    'auto_confirm_nudge' => 'Jeszcze jedno potwierdzenie i podobne powiązania będą potwierdzane automatycznie.',

    'confirm' => 'Potwierdź',
    'reject' => 'Odrzuć',
    'confirm_aria' => 'Potwierdź powiązanie łańcucha :id',
    'reject_aria' => 'Odrzuć powiązanie łańcucha :id',
    'show_more' => 'Pokaż więcej',

    'kind' => [
        'paypal_funding' => 'Finansowanie PayPal',
        'ics_bulk_settle' => 'Zbiorcze rozliczenie iDEAL',
    ],

    'errors' => [
        'confirm_hint' => 'Ten kandydat to wskazówka — otwórz go i dołącz pasującą transakcję przed potwierdzeniem.',
        'reject_hint' => 'Ten kandydat to wskazówka — otwórz go i dołącz pasującą transakcję przed odrzuceniem.',
    ],
];
