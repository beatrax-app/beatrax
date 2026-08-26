<?php

declare(strict_types=1);

return [
    'page_title' => 'Afwijkingswaarschuwingen',
    'heading' => 'Meldingen',
    'intro_anomaly' => 'Losse afschrijvingen die er voor jou ongewoon uitzien.',
    'intro_drift' => 'Goedgekeurde terugkerende reeksen waarvan de laatste afschrijving buiten je drempel valt.',
    'adjust_threshold' => 'Drempel aanpassen →',
    'adjust_sensitivity' => 'Gevoeligheid aanpassen →',

    'type_aria' => 'Type melding',
    'type' => [
        'drift' => 'Abonnementsdrift',
        'anomaly' => 'Ongewone afschrijvingen',
    ],

    'lifecycle_aria' => 'Levensloop van melding',
    'tabs' => [
        'open' => 'Open',
        'history' => 'Geschiedenis',
        'dismissed' => 'Verworpen',
    ],

    'load_more' => 'Meer laden',
    'group_count' => ':count open drift|:count open drifts',

    'anomaly_empty' => [
        'open_heading' => 'Geen ongewone afschrijvingen',
        'open_body' => 'Beatrax houdt je uitgaven in de gaten en markeert afschrijvingen die er ongewoon uitzien. Zodra er iets ongewoons binnenkomt, verschijnt het hier.',
        'history_heading' => 'Nog geen bevestigde afschrijvingen',
        'history_body' => 'Afschrijvingen die je hebt bevestigd verschijnen hier, zodat je ziet wat je al hebt bekeken.',
        'dismissed_heading' => 'Nog niets verworpen',
        'dismissed_body' => 'Wanneer je een afschrijving als verwacht markeert, komt die hier terecht met de bijbehorende onderdrukkingsregel.',
    ],

    'empty_open' => [
        'heading' => 'Geen open driftmeldingen',
        'body' => 'Beatrax houdt je goedgekeurde terugkerende reeksen in de gaten en markeert elke reeks waarvan de laatste afschrijving meer dan je drempel afwijkt van het vorige bedrag. Pas de drempel aan bij',
        'link' => 'Instellingen → Standaard driftmelding',
    ],
    'empty_history' => [
        'heading' => 'Nog geen bevestigde drifts',
        'body' => 'Bevestigde driftmeldingen verschijnen hier, zodat je ziet wat je al hebt bekeken.',
    ],
    'empty_dismissed' => [
        'heading' => 'Nog niets verworpen',
        'body' => 'Wanneer je Beatrax vertelt dat je een reeks hebt opgezegd, komt die beslissing hier terecht met een tijdstempel.',
    ],

    'row' => [
        'per_year' => '/jr',
        'meta_prior_now' => 'eerst :prior → nu :now',
        'meta_detected' => 'gedetecteerd :date',
        'meta_threshold' => 'drempel ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/jr)',
        'cancel_impact' => 'Dit opzeggen → bespaar :amount/jr',
        'cadence_flipped' => 'Frequentie gewijzigd — ook zichtbaar in',
        'cadence_flipped_link' => 'Terugkerend beoordelen',
        'acknowledge' => 'Bevestigen',
        'acknowledge_aria' => 'Driftmelding :id bevestigen',
        'snooze' => 'Sluimeren ▾',
        'snooze_1w' => '1 week',
        'snooze_1m' => '1 maand',
        'snooze_3m' => '3 maanden',
        'model_cancel' => 'Opzegging simuleren ↗',
        'model_cancel_aria' => 'Opzegging simuleren — simuleert de opzegging in de prognose voor driftmelding :id',
        'cancelled' => 'Ik heb dit opgezegd',
        'cancelled_aria' => 'Ik heb dit opgezegd — verwerpt driftmelding :id als opgezegd',
    ],

    'toasts' => [
        'acknowledged' => 'Bevestigd',
        'snoozed' => 'Gesluimerd',
        'dismissed' => 'Verworpen',
        'suppression_added' => 'Onderdrukkingsregel toegevoegd — Ongedaan maken',
        'dismissed_expected' => 'Verworpen als verwacht',
        'reopened' => 'Heropend',
        'dismissed_cancelled' => 'Verworpen als opgezegd',
    ],
];
