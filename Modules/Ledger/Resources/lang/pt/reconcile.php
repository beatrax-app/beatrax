<?php

declare(strict_types=1);

return [
    'page_title' => 'Reconciliar',
    'heading' => 'Reconciliar',
    'intro' => 'Confirma o saldo do extrato de uma conta face às tuas transações compensadas. Quando coincidirem, conclui a reconciliação para fixar essas linhas.',

    'account' => 'Conta',
    'choose_account' => 'Escolhe uma conta…',
    'statement_date' => 'Data do extrato',
    'statement_balance' => 'Saldo do extrato (€)',
    'balance_help' => 'Pré-preenchido a partir do teu extrato importado mais recente, quando disponível — negativo para dinheiro em dívida, editável nos dois sentidos.',

    'cleared_balance' => 'Saldo compensado',
    'statement_target' => 'Alvo do extrato',
    'difference' => 'Diferença',

    'pill' => [
        'choose_account' => 'escolhe uma conta',
        'enter_balance' => 'introduz um saldo do extrato',
        'matched' => 'coincide — :amount',
        'discrepancy' => 'discrepância — :amount',
    ],

    'mismatch_html' => 'O saldo do extrato ainda não coincide com o teu saldo compensado. Alterna as linhas compensadas na <a href=":url" class="underline">lista de transações</a> ou ajusta o saldo introduzido até a diferença chegar a zero — este fluxo nunca cria um lançamento de acerto.',

    'check' => 'Verificar',
    'complete' => 'Concluir a reconciliação',

    'errors' => [
        'choose_account' => 'Escolhe primeiro uma conta.',
        'invalid_balance_date' => 'Introduz um saldo do extrato e uma data válidos.',
        'mismatch' => 'O saldo do extrato ainda não corresponde ao saldo compensado — ajusta as linhas compensadas ou o saldo introduzido até a diferença ser zero.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Não há nada para fixar nesta data de extrato.',
        'complete' => 'Reconciliação concluída — :count linha fixada.|Reconciliação concluída — :count linhas fixadas.',
    ],
];
