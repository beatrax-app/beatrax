<?php

declare(strict_types=1);

return [
    'page_title' => 'Reconciliar',
    'heading' => 'Reconciliar',
    'intro' => 'Confirma o saldo do extrato de uma conta face às tuas transações compensadas. Quando coincidirem, conclui a reconciliação para fixar essas linhas.',

    'account' => 'Conta',
    'choose_account' => 'Escolhe uma conta…',
    'statement_date' => 'Data do extrato',
    'statement_balance' => 'Saldo do extrato (:symbol)',
    'balance_help' => 'Pré-preenchido a partir do teu extrato importado mais recente, quando disponível — negativo para dinheiro em dívida, editável nos dois sentidos.',

    'cleared_balance' => 'Saldo compensado',
    'statement_target' => 'Alvo do extrato',
    'difference' => 'Diferença',

    'pill' => [
        'choose_account' => 'escolhe uma conta',
        'choose_date' => 'escolhe a data do extrato',
        'enter_balance' => 'introduz um saldo do extrato',
        'matched' => 'coincide — :amount',
        'discrepancy' => 'discrepância — :amount',
        'reconciled_through' => 'reconciliada até :date',
    ],

    'mismatch_html' => 'O saldo do extrato ainda não coincide com o teu saldo compensado. Alterna as linhas compensadas na <a href=":url" class="underline">lista de transações</a> ou ajusta o saldo introduzido até a diferença chegar a zero — este fluxo nunca cria um lançamento de acerto.',
    'unreachable_no_baseline_html' => 'Nenhuma combinação de linhas consegue levar esta diferença a zero. Esta conta não tem saldo inicial registado, por isso o seu saldo é medido a partir de zero. Importa o extrato com que a conta abre, ou define o saldo inicial nas <a href=":url" class="underline">Definições</a>.',
    'unreachable' => 'Nenhuma combinação de linhas consegue levar esta diferença a zero: fica fora do intervalo de todas as linhas desta conta até à data indicada. Verifica a data do extrato e o saldo introduzido.',

    'check' => 'Verificar',
    'complete' => 'Concluir a reconciliação',
    'complete_unavailable' => 'Até esta data já não há nada para fixar — marca mais linhas como compensadas ou escolhe uma data do extrato posterior.',

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
