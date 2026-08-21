<?php

declare(strict_types=1);

return [
    'page_title' => 'Advarsler',
    'heading' => 'Advarsler',
    'intro_anomaly' => 'Enkelte posteringer, der ser usædvanlige ud for dig.',
    'intro_drift' => 'Godkendte tilbagevendende serier, hvis seneste postering endte uden for din tærskel.',
    'adjust_threshold' => 'Justér tærskel →',
    'adjust_sensitivity' => 'Justér følsomhed →',

    'type_aria' => 'Type advarsel',
    'type' => [
        'drift' => 'Abonnementsafvigelser',
        'anomaly' => 'Usædvanlige posteringer',
    ],

    'lifecycle_aria' => 'Advarslens livscyklus',
    'tabs' => [
        'open' => 'Åbne',
        'history' => 'Historik',
        'dismissed' => 'Lukkede',
    ],

    'load_more' => 'Indlæs mere',
    'group_count' => ':count åben afvigelse|:count åbne afvigelser',

    'anomaly_empty' => [
        'open_heading' => 'Ingen usædvanlige posteringer',
        'open_body' => 'Beatrax holder øje med dine udgifter og markerer posteringer, der ser usædvanlige ud. Når noget usædvanligt dukker op, vises det her.',
        'history_heading' => 'Ingen bekræftede posteringer endnu',
        'history_body' => 'Posteringer, du har bekræftet, vises her, så du kan se, hvad du allerede har gennemgået.',
        'dismissed_heading' => 'Intet lukket endnu',
        'dismissed_body' => 'Når du markerer en postering som forventet, havner den her sammen med sin undtagelsesregel.',
    ],

    'empty_open' => [
        'heading' => 'Ingen åbne afvigelsesadvarsler',
        'body' => 'Beatrax holder øje med dine godkendte tilbagevendende serier og markerer dem, hvis seneste postering afviger mere end din tærskel fra det forrige beløb. Justér tærsklen under',
        'link' => 'Indstillinger → Standardafvigelsesadvarsel',
    ],
    'empty_history' => [
        'heading' => 'Ingen bekræftede afvigelser endnu',
        'body' => 'Bekræftede afvigelsesadvarsler vises her, så du kan se, hvad du allerede har gennemgået.',
    ],
    'empty_dismissed' => [
        'heading' => 'Intet lukket endnu',
        'body' => 'Når du fortæller Beatrax, at du har opsagt en serie, havner den beslutning her med et tidsstempel.',
    ],

    'row' => [
        'per_year' => '/år',
        'meta_prior_now' => 'tidligere :prior → nu :now',
        'meta_detected' => 'opdaget :date',
        'meta_threshold' => 'tærskel ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/år)',
        'cancel_impact' => 'Opsig denne → spar :amount/år',
        'cadence_flipped' => 'Intervallet er ændret — vises også i',
        'cadence_flipped_link' => 'Gennemgå tilbagevendende',
        'acknowledge' => 'Bekræft',
        'acknowledge_aria' => 'Bekræft afvigelsesadvarsel :id',
        'snooze' => 'Udsæt ▾',
        'snooze_1w' => '1 uge',
        'snooze_1m' => '1 måned',
        'snooze_3m' => '3 måneder',
        'model_cancel' => 'Simulér opsigelse ↗',
        'model_cancel_aria' => 'Simulér opsigelse — simulerer opsigelsen i prognosen for afvigelsesadvarsel :id',
        'cancelled' => 'Jeg har opsagt denne',
        'cancelled_aria' => 'Jeg har opsagt denne — lukker afvigelsesadvarsel :id som opsagt',
    ],

    'toasts' => [
        'acknowledged' => 'Bekræftet',
        'snoozed' => 'Udsat',
        'dismissed' => 'Lukket',
        'suppression_added' => 'Undtagelsesregel tilføjet — Fortryd',
        'dismissed_expected' => 'Lukket som forventet',
        'reopened' => 'Genåbnet',
        'dismissed_cancelled' => 'Lukket som opsagt',
    ],
];
