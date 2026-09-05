<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importação concluída',
        'receipts' => 'Novos recibos encontrados',
        'manual_entry' => 'Livro de caixa atualizado',
        'migration_finished' => 'Migração concluída',
        'drift' => 'Uma cobrança recorrente mudou',
        'forecast' => 'Défice de tesouraria à vista',
        'budget_nudge' => 'Orçamento quase esgotado',
        'budget_nudge_spent' => 'Orçamento esgotado',
        'budget_nudge_over' => 'Orçamento ultrapassado',
        'savings_prompt' => 'Um sítio onde podias poupar',
        'ics_statement_ready' => 'Novo extrato ICS disponível',
        'payment_reminder_confident' => 'Pagamento vence :day (:date)',
        'payment_reminder_hedged' => 'Pagamento vence por volta de :day (:date)',
        'position_digest_daily' => 'A tua posição diária',
        'position_digest_weekly' => 'A tua posição semanal',
    ],

    'body' => [
        'budget_nudge' => ':category — gasto :spent de :budget.',
        'receipts_matched' => ':count recibo associado a partir do teu e-mail.|:count recibos associados a partir do teu e-mail.',
        'import_finished' => ':count transação importada.|:count transações importadas.',
        'manual_entry' => ':count entrada adicionada à mão.|:count entradas adicionadas à mão.',
        'migration_finished' => 'O teu orçamento foi transferido, incluindo :count transação.|O teu orçamento foi transferido, incluindo :count transações.',
        'drift' => 'Uma cobrança recorrente variou :direction: :amount.',
        'forecast' => 'O teu saldo previsto desce abaixo de zero a :date.',
        'forecast_buffer' => 'O teu saldo previsto desce abaixo da tua margem de :buffer a :date.',
        'ics_statement_ready' => 'Transfere-o do portal ICS e larga-o no Beatrax para manteres os gastos deste cartão atualizados.',
        'payment_reminder_hedged' => ':name — previsto por volta de :day (:date), :amount.',
        'payment_reminder_confident' => ':name — vence :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'em alta',
        'down' => 'em baixa',
    ],

    'digest' => [
        'nothing_notable' => 'Não há nada que precise da tua atenção.',
        'flow' => 'Entradas :in, saídas :out, líquido :net.',
        'net_worth' => 'Património líquido :amount.',
        'over_budget' => ':amount acima do orçamento até agora.',
        'payments_due' => ':count pagamento vence neste período.|:count pagamentos vencem neste período.',
        'shortfall' => 'Aproxima-se um défice de tesouraria.',
        'forecast_not_run' => 'Ainda não foi executada nenhuma previsão de tesouraria.',
    ],
];
