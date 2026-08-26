<?php

declare(strict_types=1);

return [
    'page_title' => 'Avviksvarsler',
    'heading' => 'Varsler',
    'intro_anomaly' => 'Enkeltbelastninger som ser uvanlige ut for deg.',
    'intro_drift' => 'Godkjente gjentakende serier der den siste belastningen havnet utenfor terskelen din.',
    'adjust_threshold' => 'Juster terskel →',
    'adjust_sensitivity' => 'Juster følsomhet →',

    'type_aria' => 'Type varsel',
    'type' => [
        'drift' => 'Abonnementsavvik',
        'anomaly' => 'Uvanlige belastninger',
    ],

    'lifecycle_aria' => 'Varselets livssyklus',
    'tabs' => [
        'open' => 'Åpne',
        'history' => 'Historikk',
        'dismissed' => 'Lukkede',
    ],

    'load_more' => 'Last inn mer',
    'group_count' => ':count åpent avvik|:count åpne avvik',

    'anomaly_empty' => [
        'open_heading' => 'Ingen uvanlige belastninger',
        'open_body' => 'Beatrax følger med på utgiftene dine og merker belastninger som ser uvanlige ut. Når noe uvanlig dukker opp, vises det her.',
        'history_heading' => 'Ingen bekreftede belastninger ennå',
        'history_body' => 'Belastninger du har bekreftet, vises her slik at du ser hva du allerede har gjennomgått.',
        'dismissed_heading' => 'Ingenting lukket ennå',
        'dismissed_body' => 'Når du markerer en belastning som forventet, havner den her sammen med unntaksregelen sin.',
    ],

    'empty_open' => [
        'heading' => 'Ingen åpne avviksvarsler',
        'body' => 'Beatrax følger med på de godkjente gjentakende seriene dine og merker dem der den siste belastningen avviker mer enn terskelen din fra forrige beløp. Juster terskelen under',
        'link' => 'Innstillinger → Standard avviksvarsel',
    ],
    'empty_history' => [
        'heading' => 'Ingen bekreftede avvik ennå',
        'body' => 'Bekreftede avviksvarsler vises her slik at du ser hva du allerede har gjennomgått.',
    ],
    'empty_dismissed' => [
        'heading' => 'Ingenting lukket ennå',
        'body' => 'Når du forteller Beatrax at du har sagt opp en serie, havner den beslutningen her med et tidsstempel.',
    ],

    'row' => [
        'per_year' => '/år',
        'meta_prior_now' => 'tidligere :prior → nå :now',
        'meta_detected' => 'oppdaget :date',
        'meta_threshold' => 'terskel ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/år)',
        'cancel_impact' => 'Si opp denne → spar :amount/år',
        'cadence_flipped' => 'Intervallet er endret — vises også i',
        'cadence_flipped_link' => 'Gjennomgå gjentakende',
        'acknowledge' => 'Bekreft',
        'acknowledge_aria' => 'Bekreft avviksvarsel :id',
        'snooze' => 'Utsett ▾',
        'snooze_1w' => '1 uke',
        'snooze_1m' => '1 måned',
        'snooze_3m' => '3 måneder',
        'model_cancel' => 'Simuler oppsigelse ↗',
        'model_cancel_aria' => 'Simuler oppsigelse — simulerer oppsigelsen i prognosen for avviksvarsel :id',
        'cancelled' => 'Jeg har sagt opp denne',
        'cancelled_aria' => 'Jeg har sagt opp denne — lukker avviksvarsel :id som oppsagt',
    ],

    'toasts' => [
        'acknowledged' => 'Bekreftet',
        'snoozed' => 'Utsatt',
        'dismissed' => 'Lukket',
        'suppression_added' => 'Unntaksregel lagt til — Angre',
        'dismissed_expected' => 'Lukket som forventet',
        'reopened' => 'Gjenåpnet',
        'dismissed_cancelled' => 'Lukket som oppsagt',
    ],
];
