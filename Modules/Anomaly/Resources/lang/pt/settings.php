<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Sensibilidade dos alertas',
    'sensitivity_help' => "Com que facilidade o Beatrax considera uma cobrança invulgar para esse comerciante ou categoria, de 1 a 100. Mais alto assinala mais.",

    'min_amount_label' => 'Montante mínimo da cobrança',
    'min_amount_help' => 'Ignora anomalias em cobranças abaixo deste montante. Guardado em cêntimos (:symbol) — 1000 significa :example.',

    'save' => 'Guardar definições de anomalias',
    'saved' => 'Guardado.',

    'suppression' => [
        'summary' => 'Regras de supressão',
        'empty' => 'Ainda não há regras de supressão. Quando marcares uma cobrança como esperada, aparece aqui uma regra.',
        'remove' => 'Remover',
        'remove_aria' => 'Remover regra de supressão',
        'removed_toast' => 'Regra removida',
    ],

    'unknown_merchant' => 'Comerciante desconhecido',

    'detectors' => [
        'large' => 'Cobrança elevada',
        'first_time' => 'Primeira vez',
        'duplicate' => 'Duplicado',
    ],

    'errors' => [
        'sensitivity_range' => 'A sensibilidade tem de estar entre 1 e 100.',
        'min_amount_negative' => 'O montante mínimo da cobrança não pode ser negativo.',
    ],
];
