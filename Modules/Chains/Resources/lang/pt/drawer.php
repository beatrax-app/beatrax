<?php

declare(strict_types=1);

return [
    'heading_named' => 'Cadeia de :name',
    'heading' => 'Cadeia',

    'unresolved_heading' => 'Cadeia ainda não resolvida',
    'unresolved_body' => 'O resolvedor de cadeias ainda está a correr. Abre a fila de revisão ou atualiza daqui a pouco.',

    'none_heading' => 'Não foi encontrada nenhuma cadeia de financiamento',
    'none_body' => 'Esta transação não tem nenhuma cadeia de financiamento detetada. Se esperavas uma, submete uma candidata a partir da fila de revisão.',

    'none_beyond_leg' => 'Não foi encontrada nenhuma cadeia de financiamento para além deste troço.',

    'covers_charges' => 'Cobre :count cobrança ICS|Cobre :count cobranças ICS',
    'no_ics_charges' => 'Não há cobranças ICS nesta liquidação',
    'show_more_fanout' => 'Mostrar mais :count · :shown de :total',

    'confirm' => 'Confirmar',
    'reject' => 'Rejeitar',
    'confirm_aria' => 'Confirmar a ligação de cadeia :id',
    'reject_aria' => 'Rejeitar a ligação de cadeia :id',

    'confidence_aria' => [
        'deterministic' => 'Confiança: correspondência determinística',
        'confirmed' => 'Confiança: confirmada',
        'candidate' => 'Confiança: candidata; precisa de revisão',
    ],
];
