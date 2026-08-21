<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importação concluída',
        'receipts' => 'Novos recibos encontrados',
        'drift' => 'Uma cobrança recorrente mudou',
        'forecast' => 'Défice de tesouraria à vista',
        'budget_nudge' => 'Orçamento quase esgotado',
        'savings_prompt' => 'Existe um plano mais barato',
        'ics_statement_ready' => 'Novo extrato ICS disponível',
        'payment_reminder_confident' => 'Pagamento vence :day',
        'payment_reminder_hedged' => 'Pagamento vence por volta de :day',
        'position_digest_daily' => 'A tua posição diária',
        'position_digest_weekly' => 'A tua posição semanal',
    ],

    'body' => [
        'budget_nudge' => ':category — gasto :spent de :budget.',
        'receipts_matched' => ':count recibo associado a partir do teu e-mail.|:count recibos associados a partir do teu e-mail.',
        'import_finished' => ':count transação importada.|:count transações importadas.',
        'drift' => 'Uma cobrança recorrente variou :direction: :amount.',
        'forecast' => 'O teu saldo previsto desce abaixo de zero nos próximos 30 dias.',
        'ics_statement_ready' => 'Transfere-o do portal ICS e larga-o no Beatrax para manteres os gastos deste cartão atualizados.',
        'payment_reminder_hedged' => ':name — previsto por volta de :day, :amount.',
        'payment_reminder_confident' => ':name — vence :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mês)',
    ],

    'drift_direction' => [
        'up' => 'em alta',
        'down' => 'em baixa',
    ],

    'digest' => [
        'nothing_notable' => 'Não há nada que precise da tua atenção.',
        'flow' => 'Entradas :in, saídas :out, líquido :net.',
        'over_budget' => ':amount acima do orçamento até agora.',
        'payments_due' => '1 pagamento vence neste período.|:count pagamentos vencem neste período.',
        'shortfall' => 'Aproxima-se um défice de tesouraria.',
    ],
];
