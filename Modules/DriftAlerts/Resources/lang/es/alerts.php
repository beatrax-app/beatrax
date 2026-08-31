<?php

declare(strict_types=1);

return [
    'page_title' => 'Alertas de desviación',
    'intro_anomaly' => 'Cargos concretos que parecen fuera de lo habitual en tu caso.',
    'intro_drift' => 'Series recurrentes aprobadas cuyo último cargo se ha salido de tu umbral.',
    'adjust_threshold' => 'Ajustar el umbral →',
    'adjust_sensitivity' => 'Ajustar la sensibilidad →',

    'type_aria' => 'Tipo de alerta',
    'type' => [
        'drift' => 'Desviación de suscripciones',
        'anomaly' => 'Cargos inusuales',
    ],

    'lifecycle_aria' => 'Ciclo de vida de la alerta',
    'tabs' => [
        'open' => 'Abiertas',
        'history' => 'Historial',
        'dismissed' => 'Descartadas',
    ],

    'load_more' => 'Cargar más',
    'group_count' => ':count desviación abierta|:count desviaciones abiertas',

    'anomaly_empty' => [
        'open_heading' => 'No hay cargos inusuales',
        'open_body' => 'Beatrax vigila tu gasto y señala los cargos que parecen fuera de lo habitual. Cuando llegue algo inusual, aparecerá aquí.',
        'history_heading' => 'Aún no has confirmado ningún cargo',
        'history_body' => 'Los cargos que confirmes aparecerán aquí para que veas lo que ya has revisado.',
        'dismissed_heading' => 'Aún no has descartado nada',
        'dismissed_body' => 'Cuando marques un cargo como previsto, aparecerá aquí con su regla de supresión.',
    ],

    'empty_open' => [
        'heading' => 'No hay alertas de desviación abiertas',
        'body' => 'Beatrax vigila tus series recurrentes aprobadas y señala aquellas cuyo último cargo se aparta del importe anterior más allá de tu umbral. Ajusta el umbral en',
        'link' => 'Ajustes → Alerta de desviación por defecto',
    ],
    'empty_history' => [
        'heading' => 'Aún no has confirmado ninguna desviación',
        'body' => 'Las alertas de desviación confirmadas aparecerán aquí para que veas lo que ya has revisado.',
    ],
    'empty_dismissed' => [
        'heading' => 'Aún no has descartado nada',
        'body' => 'Cuando le digas a Beatrax que has cancelado una serie, esa decisión aparecerá aquí con su fecha y hora.',
    ],

    'row' => [
        'per_year' => '/año',
        'meta_prior_now' => 'antes :prior → ahora :now',
        'meta_detected' => 'detectado el :date',
        'meta_threshold' => 'umbral ±:percent %',
        'meta_eur_equiv' => '(≈ :amount/año)',
        'cancel_impact' => 'Cancela esto → ahorra :amount/año',
        'cadence_flipped' => 'La frecuencia ha cambiado — también aparece en',
        'cadence_flipped_link' => 'Revisar recurrentes',
        'acknowledge' => 'Confirmar',
        'acknowledge_aria' => 'Confirmar la alerta de desviación :id',
        'snooze' => 'Posponer ▾',
        'snooze_1w' => '1 semana',
        'snooze_1m' => '1 mes',
        'snooze_3m' => '3 meses',
        'model_cancel' => 'Simular cancelación ↗',
        'model_cancel_aria' => 'Simular cancelación — refleja la cancelación en la previsión de la alerta de desviación :id',
        'cancelled' => 'He cancelado esto',
        'cancelled_aria' => 'He cancelado esto — descarta la alerta de desviación :id como cancelada',
    ],

    'toasts' => [
        'gone' => 'Esa alerta ya no existe.',
        'acknowledged' => 'Confirmada',
        'snoozed' => 'Pospuesta',
        'dismissed' => 'Descartada',
        'suppression_added' => 'Regla de supresión añadida — Deshacer',
        'dismissed_expected' => 'Descartada como prevista',
        'reopened' => 'Reabierta',
        'dismissed_cancelled' => 'Descartada como cancelada',
    ],
];
