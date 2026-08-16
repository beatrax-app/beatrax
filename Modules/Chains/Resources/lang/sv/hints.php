<?php

declare(strict_types=1);

return [
    'page_title' => 'Kedjeledtrådar',
    'heading' => 'Ledtrådar',
    'back_to_review' => '← Tillbaka till granskningskön',
    'subtitle' => 'Kandidater som en matchare tog fram utan någon matchande motsvarighet. Varje ledtråd löser sig själv vid nästa kedjekörning, eller så kan du stänga den här när du har konstaterat att den inte kommer att göra det.',

    'empty_heading' => 'Inga ledtrådar att sortera',
    'empty_body' => 'När en matchare hittar en kedja som den inte kunde lösa automatiskt dyker den upp här.',

    'no_counterparty' => '(ingen motpart)',
    'unknown_account' => '(okänt konto)',

    'dismiss' => 'Stäng',
    'dismiss_aria' => 'Stäng ledtråd :id',
    'dismissed' => 'Ledtråden är stängd.',

    'kind' => [
        'ics_bulk_settle' => 'Samlad iDEAL-avräkning (utanför toleransen)',
        'funded_by_card_hint' => 'Finansierad med kort (ledtråd)',
        'refund_of_hint' => 'Återbetalning (ledtråd)',
    ],
];
