<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalender',
        'subtitle' => 'Aankomende betalingen en je verwachte dagsaldo.',
    ],

    'summary' => [
        'computing' => 'Prognose wordt bijgewerkt…',
        'risk' => 'Saldo daalt onder € 0 op :date.|Saldo daalt onder € 0 op :count dagen — eerste: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Vorige maand',
        'next_month' => 'Volgende maand',
        'accounts' => 'Rekeningen',
        'popover_aria' => 'Weergave-instellingen voor rekeningen',
        'no_accounts' => 'Geen rekeningen gevonden.',
        'col_account' => 'Rekening',
        'col_entries' => 'Betalingen',
        'col_balance' => 'Saldo',
        'show_entries_aria' => 'Toon betalingen voor :name',
        'count_balance_aria' => 'Reken :name mee in het saldo',
    ],

    'empty' => [
        'heading' => 'Geen aankomende betalingen',
        'body' => 'Koppel een rekening of keur een terugkerende reeks goed om je verwachte betalingen op de kalender te zien.',
        'review' => 'Terugkerend beoordelen →',
    ],

    'weekdays' => [
        'mon' => 'ma',
        'tue' => 'di',
        'wed' => 'wo',
        'thu' => 'do',
        'fri' => 'vr',
        'sat' => 'za',
        'sun' => 'zo',
    ],

    'grid' => [
        'aria' => 'kalender van :month',
    ],

    'cell' => [
        'entry' => 'betaling|betalingen',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', verwacht saldo min €:amount',
        'aria_balance_positive' => ', verwacht saldo €:amount',
        'overflow' => '+:count meer',
        'paid' => 'Betaald',
        'missed' => 'Verwacht — niet gevonden',
    ],

    'panel' => [
        'aria' => 'Detailpaneel dag',
        'close' => 'Dagpaneel sluiten',
        'start_of_day' => 'Begin van de dag',
        'no_payments' => 'Geen betalingen op deze dag.',
        'date_approximate' => '~ datum bij benadering',
        'series' => '↗ reeks',
        'counterparty' => '↗ tegenpartij',
        'end_of_day' => 'Einde van de dag',
    ],
];
