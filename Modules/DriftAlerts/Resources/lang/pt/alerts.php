<?php

declare(strict_types=1);

return [
    'page_title' => 'Alertas',
    'heading' => 'Alertas',
    'intro_anomaly' => 'Cobranças individuais que parecem fora do normal para ti.',
    'intro_drift' => 'Séries recorrentes aprovadas cuja última cobrança saiu do teu limite.',
    'adjust_threshold' => 'Ajustar o limite →',
    'adjust_sensitivity' => 'Ajustar a sensibilidade →',

    'type_aria' => 'Tipo de alerta',
    'type' => [
        'drift' => 'Desvio de subscrição',
        'anomaly' => 'Cobranças invulgares',
    ],

    'lifecycle_aria' => 'Ciclo de vida do alerta',
    'tabs' => [
        'open' => 'Em aberto',
        'history' => 'Histórico',
        'dismissed' => 'Dispensados',
    ],

    'load_more' => 'Carregar mais',
    'group_count' => ':count desvios em aberto',

    'anomaly_empty' => [
        'open_heading' => 'Não há cobranças invulgares',
        'open_body' => 'O Beatrax vigia as tuas despesas e assinala as cobranças que parecem fora do normal. Quando surge algo invulgar, aparece aqui.',
        'history_heading' => 'Ainda não há cobranças confirmadas',
        'history_body' => 'As cobranças que confirmaste aparecem aqui para veres o que já analisaste.',
        'dismissed_heading' => 'Ainda não dispensaste nada',
        'dismissed_body' => 'Quando marcas uma cobrança como esperada, ela vai parar aqui com a respetiva regra de supressão.',
    ],

    'empty_open' => [
        'heading' => 'Não há alertas de desvio em aberto',
        'body' => 'O Beatrax vigia as tuas séries recorrentes aprovadas e assinala aquelas cuja última cobrança difere do montante anterior mais do que o teu limite. Ajusta o limite em',
        'link' => 'Definições → Alerta de desvio predefinido',
    ],
    'empty_history' => [
        'heading' => 'Ainda não há desvios confirmados',
        'body' => 'Os alertas de desvio confirmados aparecem aqui para veres o que já analisaste.',
    ],
    'empty_dismissed' => [
        'heading' => 'Ainda não dispensaste nada',
        'body' => 'Quando dizes ao Beatrax que cancelaste uma série, essa decisão vai parar aqui com data e hora.',
    ],

    'row' => [
        'per_year' => '/ano',
        'meta_prior_now' => 'anterior :prior → agora :now',
        'meta_detected' => 'detetado a :date',
        'meta_threshold' => 'limite ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/ano)',
        'cancel_impact' => 'Cancelar isto → poupas :amount/ano',
        'cadence_flipped' => 'A periodicidade mudou — também aparece em',
        'cadence_flipped_link' => 'Rever recorrentes',
        'acknowledge' => 'Confirmar',
        'acknowledge_aria' => 'Confirmar o alerta de desvio :id',
        'snooze' => 'Adiar ▾',
        'snooze_1w' => '1 semana',
        'snooze_1m' => '1 mês',
        'snooze_3m' => '3 meses',
        'model_cancel' => 'Simular cancelamento ↗',
        'model_cancel_aria' => 'Simular cancelamento — modela o cancelamento na previsão do alerta de desvio :id',
        'cancelled' => 'Já cancelei isto',
        'cancelled_aria' => 'Já cancelei isto — dispensa o alerta de desvio :id como cancelado',
    ],

    'toasts' => [
        'acknowledged' => 'Confirmado',
        'snoozed' => 'Adiado',
        'dismissed' => 'Dispensado',
        'suppression_added' => 'Regra de supressão adicionada — Anular',
        'dismissed_expected' => 'Dispensado como esperado',
        'reopened' => 'Reaberto',
        'dismissed_cancelled' => 'Dispensado como cancelado',
    ],
];
