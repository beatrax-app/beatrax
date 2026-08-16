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
    'open_from_row' => 'Von-Zeile öffnen',
    'open_to_row' => 'Nach-Zeile öffnen',
    'leg_count' => '1 Zahlung|:count Zahlungen',
    'state_aria' => 'Status: :state',

    'kind' => [
        'paypal_funding' => 'PayPal-Finanzierung',
        'ics_bulk_settle' => 'iDEAL-Sammelabrechnung',
        'funded_by_card_hint' => 'Mit Karte finanziert (Hinweis)',
        'refund_of_hint' => 'Rückerstattung (Hinweis)',
    ],
];
