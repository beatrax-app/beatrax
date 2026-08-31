<?php

declare(strict_types=1);

return [
    'page_title' => 'Översikt',
    'subtitle' => 'Den här perioden i korthet.',

    'previous_period' => 'Föregående period',
    'today' => 'I dag',
    'next_period' => 'Nästa period',

    'totals_aria' => 'Totalt den här perioden',
    'totals_aria_currency' => 'Totalt den här perioden — :currency',
    'in' => 'In',
    'out' => 'Ut',
    'net' => 'Netto',

    'status_tiles_aria' => 'Statusrutor',
    'email_scan_health' => 'Status för e-postskanning — :count ansluten inkorg|Status för e-postskanning — :count anslutna inkorgar',

    'top_spending' => 'Största utgifter',
    'no_expenses' => 'Inga kategoriserade utgifter än.',
    'top_spending_refunded' => 'Inte rankad — :amount kom tillbaka',

    'recent_transactions' => 'Senaste transaktioner',
    'view_all' => 'Visa alla',
    'nothing_period' => 'Inget här för den här perioden.',
    'th_date' => 'Datum',
    'th_counterparty' => 'Motpart',
    'th_category' => 'Kategori',
    'th_amount' => 'Belopp',
    'uncategorized' => 'Okategoriserat',

    'jump_to_records' => [
        'body' => 'Inget för den här perioden. Dina senaste transaktioner finns kvar.',
        'action' => 'Visa :period',
    ],

    'reauth' => [
        'title' => 'En inkorg behöver anslutas på nytt.',
        'body' => 'En eller flera inkorgar har loggats ut — Beatrax kan inte skanna dem förrän du ansluter dem igen.',
        'link' => 'Gå till inkorgar',
        'dismiss' => 'Stäng',
    ],

    'failed_chain' => [
        'title' => 'Kedjeupplösningen misslyckades.',
        'body' => 'Ett eller flera jobb för kedjeupplösning stötte på ett fel.',
        'link' => 'Öppna köinspektören',
    ],
];
