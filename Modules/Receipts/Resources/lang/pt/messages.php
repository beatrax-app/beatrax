<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Larga aqui uma mensagem de e-mail (.eml) ou um arquivo de caixa de correio (.mbox). O comparador reconhece os recibos do PayPal e apresenta-os como transações canónicas; os remetentes sem correspondência ficam no registo de auditoria para triagem.',
    ],

    'conflict' => [

        'field' => [
            'amount_minor' => 'montante',
            'currency' => 'moeda',
            'description' => 'descrição',
            'counterparty_name' => 'nome do comerciante',
            'default' => 'valor',
        ],
        'heading_cleaner' => 'Um recibo de e-mail tem um valor mais limpo no campo :field',
        'heading_different' => 'Um recibo de e-mail regista um valor diferente no campo :field',
        'title' => 'O recibo e o extrato não coincidem.',
        'body' => ':heading (“:receipt”) do que o extrato (“:statement”). Queres que o Beatrax dê preferência aos recibos nos conflitos futuros?',
        'use_receipt' => 'Usar o recibo',
        'keep_statement' => 'Manter o extrato',
    ],
];
