<?php

declare(strict_types=1);

return [
    'page_title' => 'Ketten',
    'heading' => 'Ketten',
    'review_link' => 'Prüfwarteschlange →',
    'hints_link' => 'Hinweise →',
    'subtitle' => 'Einkäufe, die zu einer einzigen Abbuchung zusammengefasst wurden. Jede Karte zeigt eine Abbuchung und die Zahlungen darin.',

    'empty_heading' => 'Noch keine Ketten',
    'empty_body' => 'Importiere ein paar Kontoauszüge (Bank, PayPal, Karte) und der Resolver zeigt hier automatisch kontoübergreifende Ketten.',

    'no_counterparty' => '(kein Zahlungspartner)',
    'leg_count' => ':count Zahlung|:count Zahlungen',
    'legs_more' => '+ :count weitere',
    'state_aria' => 'Status: :state',

    'state' => [
        'candidate' => 'Kandidat',
        'confirmed' => 'Bestätigt',
        'rejected' => 'Abgelehnt',
    ],

    'kind' => [
        'paypal_funding' => 'PayPal-Finanzierung',
        'ics_bulk_settle' => 'iDEAL-Sammelabrechnung',
        'funded_by_card_hint' => 'Mit Karte finanziert (Hinweis)',
        'refund_of_hint' => 'Rückerstattung (Hinweis)',
    ],
];
