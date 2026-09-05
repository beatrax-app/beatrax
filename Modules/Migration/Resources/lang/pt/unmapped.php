<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Objetivo: :name',
        'category_goal' => 'Objetivo de :name',
        'schedule_untitled' => 'Transação agendada sem nome',
        'transaction' => 'Transação: :name · :date · :amount',
        'transaction_unnamed' => 'Transação',
        'amount_update' => 'Atualização do montante da transação',
        'budget_history' => 'Histórico de orçamento em :currency',
        'budget_file_currency' => 'Moeda do ficheiro de orçamento',
        'budget_file_mode' => 'Modo do ficheiro de orçamento',
    ],

    'conflict' => [
        'budget_assignment' => 'Atribuição de orçamento',
        'budget_for_month' => 'Orçamento de :category · :month',
        'budget_for_category' => 'Orçamento de :category',
        'category_name' => 'Nome da categoria',
        'category_name_of' => 'Nome da categoria «:name»',
        'account_name' => 'Nome da conta',
        'account_name_of' => 'Nome da conta «:name»',
        'transaction_amount' => 'Montante da transação',
        'transaction_amount_of' => 'Montante de :name',
        'transaction_amount_of_dated' => 'Montante de :name · :date',
        'transaction_description' => 'Descrição da transação',
        'transaction_description_of' => 'Descrição de :name',
        'transaction_description_of_dated' => 'Descrição de :name · :date',
        'other' => 'Valor importado',
    ],

    'reason' => [
        'fingerprint_collision' => 'Esta transação colidiu com outra transação já registada (impressão digital idêntica) e não foi importada.',
        'reconciled_status_kept' => 'O estado de reconciliação da origem não pôde ser aplicado — esta transação está reconciliada no Beatrax e só anular a reconciliação o altera. Deixada inalterada.',
        'split_legs_without_category' => ':count parcela de :legs não tem categoria, e uma parcela não pode ser guardada sem uma. A transação foi importada pelo montante total e está à espera na categoria :uncategorized.|:count parcelas de :legs não têm categoria, e uma parcela não pode ser guardada sem uma. A transação foi importada pelo montante total e está à espera na categoria :uncategorized.',
        'split_sum_mismatch' => 'As parcelas somam :legs mas a transação é :total, e uma divisão tem de corresponder exatamente à sua transação. A transação foi importada pelo montante total, sem as parcelas.',
        'split_unstorable' => 'O Beatrax não consegue guardar esta divisão tal como está, por isso a transação foi importada sozinha, sem as parcelas.',
        'goal_without_target_date' => 'Este objetivo não tem data-alvo; o Beatrax precisa de uma para criar um objetivo de poupança.',
        'goal_without_name' => 'Este objetivo não tem nome; o Beatrax precisa de um para criar um objetivo de poupança.',
        'goal_def_unsupported' => 'categories.goal_def usa um formato de modelo não suportado (não plano) — o objetivo não foi importado.',
        'budget_currency_mismatch' => ':count linha de orçamento não foi importada: os teus orçamentos são mantidos em :envelope e esta exportação mantém o orçamento em :source.|:count linhas de orçamento não foram importadas: os teus orçamentos são mantidos em :envelope e esta exportação mantém o orçamento em :source.',
        'amount_apply_collision' => 'Não foi possível aplicar o novo montante da origem — colide com a impressão digital de outra transação (mesma conta, data, moeda e contraparte). Ficou inalterado.',
        'amount_currency_mismatch' => 'Os montantes das transações não foram reconciliados: estas transações são mantidas em :local e esta exportação indica-as em :source. Ficaram inalterados.',
        'schedule_unsupported' => 'As transações agendadas e recorrentes ainda não podem ser criadas no Beatrax a partir de uma origem externa — foram guardadas apenas como nota, não como uma série ativa em Recorrentes.',
        'saved_report_unsupported' => 'Os relatórios guardados e as configurações de análise não têm equivalente no Beatrax.',
        'assumed_currency' => "Assumiu-se :currency — não foi encontrada nenhuma linha 'preferences.currencyCode' nesta exportação.",
        'assumed_budget_type' => "Assumiu-se :mode — não foi encontrada nenhuma linha 'preferences.budgetType' nesta exportação.",
        'changed_on_both_sides' => "Tanto o ficheiro de origem como o Beatrax alteraram isto desde a última importação.\nLocal: :local\nOrigem: :source\nÚltima importação: :baseline",
        'take_source' => 'O valor da nova exportação é aplicado quando confirmares — o teu valor local é substituído.',
        'keep_local' => 'O teu valor local é mantido — o valor da nova exportação não é aplicado.',
        'compared_values' => ":intro\nLocal: :local · Origem: :source · Última importação: :baseline",
    ],

    'value' => [
        'none' => '(nenhum)',
        'quoted' => '«:value»',
    ],
];
