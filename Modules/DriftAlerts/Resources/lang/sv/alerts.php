<?php

declare(strict_types=1);

return [
    'page_title' => 'Varningar',
    'heading' => 'Varningar',
    'intro_anomaly' => 'Enskilda debiteringar som ser ovanliga ut för dig.',
    'intro_drift' => 'Godkända återkommande serier vars senaste debitering hamnade utanför ditt tröskelvärde.',
    'adjust_threshold' => 'Justera tröskelvärde →',
    'adjust_sensitivity' => 'Justera känslighet →',

    'type_aria' => 'Typ av varning',
    'type' => [
        'drift' => 'Prenumerationsavvikelser',
        'anomaly' => 'Ovanliga debiteringar',
    ],

    'lifecycle_aria' => 'Varningens livscykel',
    'tabs' => [
        'open' => 'Öppna',
        'history' => 'Historik',
        'dismissed' => 'Stängda',
    ],

    'load_more' => 'Ladda mer',
    'group_count' => ':count öppna avvikelser',

    'anomaly_empty' => [
        'open_heading' => 'Inga ovanliga debiteringar',
        'open_body' => 'Beatrax håller koll på dina utgifter och flaggar debiteringar som ser ovanliga ut. När något ovanligt dyker upp visas det här.',
        'history_heading' => 'Inga bekräftade debiteringar än',
        'history_body' => 'Debiteringar som du har bekräftat visas här så att du ser vad du redan har granskat.',
        'dismissed_heading' => 'Inget stängt än',
        'dismissed_body' => 'När du markerar en debitering som förväntad hamnar den här tillsammans med sin undantagsregel.',
    ],

    'empty_open' => [
        'heading' => 'Inga öppna avvikelsevarningar',
        'body' => 'Beatrax håller koll på dina godkända återkommande serier och flaggar dem vars senaste debitering skiljer sig från föregående belopp med mer än ditt tröskelvärde. Justera tröskelvärdet under',
        'link' => 'Inställningar → Standardavvikelsevarning',
    ],
    'empty_history' => [
        'heading' => 'Inga bekräftade avvikelser än',
        'body' => 'Bekräftade avvikelsevarningar visas här så att du ser vad du redan har granskat.',
    ],
    'empty_dismissed' => [
        'heading' => 'Inget stängt än',
        'body' => 'När du berättar för Beatrax att du har sagt upp en serie hamnar det beslutet här med en tidsstämpel.',
    ],

    'row' => [
        'per_year' => '/år',
        'meta_prior_now' => 'tidigare :prior → nu :now',
        'meta_detected' => 'upptäckt :date',
        'meta_threshold' => 'tröskelvärde ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/år)',
        'cancel_impact' => 'Säg upp den här → spara :amount/år',
        'cadence_flipped' => 'Intervallet har ändrats — visas även i',
        'cadence_flipped_link' => 'Granska återkommande',
        'acknowledge' => 'Bekräfta',
        'acknowledge_aria' => 'Bekräfta avvikelsevarning :id',
        'snooze' => 'Skjut upp ▾',
        'snooze_1w' => '1 vecka',
        'snooze_1m' => '1 månad',
        'snooze_3m' => '3 månader',
        'model_cancel' => 'Simulera uppsägning ↗',
        'model_cancel_aria' => 'Simulera uppsägning — simulerar uppsägningen i prognosen för avvikelsevarning :id',
        'cancelled' => 'Jag har sagt upp den här',
        'cancelled_aria' => 'Jag har sagt upp den här — stänger avvikelsevarning :id som uppsagd',
    ],

    'toasts' => [
        'acknowledged' => 'Bekräftad',
        'snoozed' => 'Uppskjuten',
        'dismissed' => 'Stängd',
        'suppression_added' => 'Undantagsregel tillagd — Ångra',
        'dismissed_expected' => 'Stängd som förväntad',
        'reopened' => 'Återöppnad',
        'dismissed_cancelled' => 'Stängd som uppsagd',
    ],
];
