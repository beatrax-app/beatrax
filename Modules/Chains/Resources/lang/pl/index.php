<?php

declare(strict_types=1);

return [
    'page_title' => 'Łańcuchy',
    'heading' => 'Łańcuchy',
    'review_link' => 'Kolejka przeglądu →',
    'hints_link' => 'Wskazówki →',
    'subtitle' => 'Zakupy pobrane jako jedno obciążenie. Każda karta pokazuje jedno obciążenie i płatności, które się na nie złożyły.',

    'empty_heading' => 'Brak łańcuchów',
    'empty_body' => 'Zaimportuj kilka wyciągów (bank, PayPal, karta), a mechanizm łańcuchów automatycznie pokaże tutaj łańcuchy między kontami.',

    'no_counterparty' => '(brak kontrahenta)',
    'leg_count' => ':count płatność|:count płatności|:count płatności',
    'legs_more' => '+ jeszcze :count',
    'state_aria' => 'Stan: :state',

    'state' => [
        'candidate' => 'Kandydat',
        'confirmed' => 'Potwierdzone',
        'rejected' => 'Odrzucono',
    ],

    'kind' => [
        'paypal_funding' => 'Finansowanie PayPal',
        'ics_bulk_settle' => 'Zbiorcze rozliczenie iDEAL',
        'funded_by_card_hint' => 'Sfinansowane kartą (wskazówka)',
        'refund_of_hint' => 'Zwrot (wskazówka)',
    ],
];
