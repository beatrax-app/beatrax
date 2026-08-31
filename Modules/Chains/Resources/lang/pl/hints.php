<?php

declare(strict_types=1);

return [
    'page_title' => 'Wskazówki łańcuchów',
    'heading' => 'Wskazówki',
    'back_to_review' => '← Powrót do kolejki przeglądu',
    'subtitle' => 'Kandydaci zgłoszeni przez dopasowywacz bez pasującego odpowiednika. Podpowiedź rozliczenia znika sama, gdy dotrą brakujące obciążenia; pozostałe zostają, dopóki ich tu nie odrzucisz.',

    'empty_heading' => 'Brak wskazówek do przejrzenia',
    'empty_body' => 'Gdy mechanizm dopasowania wskaże łańcuch, którego nie da się rozwiązać automatycznie, pojawi się on tutaj.',

    'no_counterparty' => '(brak kontrahenta)',
    'unknown_account' => '(nieznane konto)',

    'dismiss' => 'Odrzuć',
    'dismiss_aria' => 'Odrzuć wskazówkę :id',
    'dismissed' => 'Wskazówka odrzucona.',

    'kind' => [
        'ics_bulk_settle' => 'Zbiorcze rozliczenie iDEAL (poza tolerancją)',
        'funded_by_card_hint' => 'Sfinansowane kartą (wskazówka)',
        'refund_of_hint' => 'Zwrot (wskazówka)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerancja: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'w stałym marginesie',
            'percent_2' => 'w marginesie procentowym',
            'exceeded' => 'poza marginesem',
            'refund_after_close' => 'zwrot po zamknięciu',
        ],
        'delta_overpaid' => 'Nadpłata: :amount',
        'delta_underpaid' => 'Brakuje :amount',
        'delta_balanced' => 'Bilansuje się dokładnie',
        'covered' => 'Objęte transakcje: :count',
        'statement' => 'Wyciąg karty #:id',
        'card_last4' => 'Karta kończąca się na :last4',
        'original_reference' => 'Pierwotny numer zamówienia: :reference',
    ],
];
