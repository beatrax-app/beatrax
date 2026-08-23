<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Calendário',
        'subtitle' => 'Próximos pagamentos e o teu saldo diário projetado.',
    ],

    'summary' => [
        'computing' => 'A atualizar a projeção…',
        'risk' => 'O saldo desce abaixo de €0 em :date.|O saldo desce abaixo de €0 em :count dias — o primeiro: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Mês anterior',
        'next_month' => 'Mês seguinte',
        'accounts' => 'Contas',
        'popover_aria' => 'Definições de apresentação das contas',
        'no_accounts' => 'Não foram encontradas contas.',
        'col_account' => 'Conta',
        'col_entries' => 'Entradas',
        'col_balance' => 'Saldo',
        'show_entries_aria' => 'Mostrar entradas de :name',
        'count_balance_aria' => 'Contar :name no saldo',
    ],

    'empty' => [
        'heading' => 'Sem pagamentos futuros',
        'body' => 'Liga uma conta ou aprova uma série recorrente para veres os pagamentos projetados no calendário.',
        'review' => 'Rever recorrentes →',
    ],

    'weekdays' => [
        'mon' => 'Seg',
        'tue' => 'Ter',
        'wed' => 'Qua',
        'thu' => 'Qui',
        'fri' => 'Sex',
        'sat' => 'Sáb',
        'sun' => 'Dom',
    ],

    'grid' => [
        'aria' => 'Calendário de :month',
    ],

    'cell' => [
        'entry' => 'entrada|entradas',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', saldo projetado menos :amount',
        'aria_balance_positive' => ', saldo projetado :amount',
        'overflow' => '+:count mais',
        'paid' => 'Pago',
        'missed' => 'Esperado — não encontrado',
    ],

    'panel' => [
        'aria' => 'Painel de detalhe do dia',
        'close' => 'Fechar o painel do dia',
        'start_of_day' => 'Início do dia',
        'no_payments' => 'Sem pagamentos neste dia.',
        'date_approximate' => '~ data aproximada',
        'series' => '↗ série',
        'counterparty' => '↗ contraparte',
        'end_of_day' => 'Fim do dia',
    ],
];
