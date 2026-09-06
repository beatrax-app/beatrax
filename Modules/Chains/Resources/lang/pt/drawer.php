<?php

declare(strict_types=1);

return [
    'heading_named' => 'Cadeia de :name',
    'heading' => 'Cadeia',

    'unresolved_heading' => 'Nenhuma transação escolhida',
    'unresolved_body' => 'Escolhe uma linha na lista de transações para veres o que a pagou.',

    'none_heading' => 'Não foi encontrada nenhuma cadeia de financiamento',
    'none_body' => 'Esta transação não tem nenhuma cadeia de financiamento detetada. Se esperavas uma, submete uma candidata a partir da fila de revisão.',

    'none_beyond_leg' => 'Não foi encontrada nenhuma cadeia de financiamento para além deste troço.',

    'covers_charges' => 'Cobre :count cobrança ICS|Cobre :count cobranças ICS',
    'show_more_fanout' => 'Mostrar mais :count · :shown de :total',

    'confirm' => 'Confirmar',
    'reject' => 'Rejeitar',
    'confirm_aria' => 'Confirmar a ligação de cadeia :id',
    'reject_aria' => 'Rejeitar a ligação de cadeia :id',

    'confidence_tier' => [
        'deterministic' => 'Determinística',
        'confirmed' => 'Confirmada',
        'candidate' => 'Candidata',
    ],

    'confidence_aria' => [
        'deterministic' => 'Confiança: correspondência determinística',
        'confirmed' => 'Confiança: confirmada',
        'candidate' => 'Confiança: candidata; precisa de revisão',
    ],
];
