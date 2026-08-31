<?php

declare(strict_types=1);

return [
    'heading' => 'Previsão',
    'page_title' => 'Previsão',
    'subtitle' => 'Para onde vai o teu saldo — nos próximos 30 a 365 dias.',
    'adjust_buffers' => 'Ajustar margens',

    'empty_heading' => 'Ainda não há dados de previsão',
    'empty_body' => 'Liga uma conta ou aprova uma série recorrente para veres o teu saldo projetado nas próximas semanas.',
    'empty_start' => 'Começa por',
    'empty_import_link' => 'importar um extrato',
    'empty_or' => 'ou',
    'empty_recurring_link' => 'rever os padrões recorrentes',

    'account_tablist' => 'Conta',
    'all_accounts' => 'Todas as contas',

    'horizon_label' => 'Horizonte da previsão',
    'n_days' => ':days dia|:days dias',

    'view_by_funder' => 'Ver por financiador',
    'view_by_funder_hint' => 'Agrupa as séries resolvidas por cadeia na conta que efetivamente as paga.',

    'scenario_group' => 'Cenário',
    'baseline' => 'Referência',
    'scenario_word' => 'Cenário',
    'new_scenario' => '+ Novo cenário',
    'scenario_name_placeholder' => 'Nome do cenário',
    'new_scenario_aria' => 'Nome do novo cenário',
    'create_scenario' => 'Criar cenário',
    'cancel' => 'Cancelar',

    'aggregate_subtitle' => 'Saldo combinado de todas as contas, projetado para o próximo :days dia.|Saldo combinado de todas as contas, projetado para os próximos :days dias.',

    'today' => 'hoje',
    'on_day' => 'no dia',

    'edit_buffer_aria' => 'Editar a margem mínima de :name',
    'buffer_not_set' => 'Margem: não definida',
    'buffer_set' => 'Margem: :amount',

    'shortfall' => 'O défice começa a :date — :amount abaixo da tua margem de :buffer',

    'compared_against_baseline' => 'Comparado com a referência acima',

    'run_failed' => 'Não foi possível calcular esta projeção. A linha abaixo mostra apenas o que já está lançado.',

    'scenario_editor_aria' => 'Editor de cenários',
    'series_confidence' => 'Confiança das séries',
    'no_series_contribute' => 'Ainda não há séries a contribuir para a previsão desta conta.',

    'net_diff' => 'Diferença líquida',

    'net_diff_unknown' => 'Ainda não calculado para este horizonte.',
    'net_diff_section_aria' => 'Diferença líquida entre a referência e o cenário nos horizontes de 30 / 60 / 90 dias',
    'net_diff_delta_aria' => 'Diferença líquida no dia :day: :value, o cenário está :state',
    'better_than_baseline' => 'melhor do que a referência',
    'worse_than_baseline' => 'pior do que a referência',
    'equal_to_baseline' => 'igual à referência',
    'at_day' => 'no dia :day',

    'updating' => 'A atualizar',
    'chart_noscript' => 'O gráfico precisa de JavaScript. O intervalo abrange :days dia.|O gráfico precisa de JavaScript. O intervalo abrange :days dias.',
    'total_balance' => 'Saldo total',
    'projection_range' => 'Intervalo de projeção',
    'point_estimate' => 'Estimativa pontual',

    'per_month_suffix' => '/mês',
    'confidence_chip_aria' => ':name, confiança :confidence — o intervalo de projeção é :percent por cento da estimativa pontual',

    'highlights_title' => 'Destaques da previsão',
    'highlights_shortfall_aria' => ':count janela de défice ativa nos próximos :days dias|:count janelas de défice ativas nos próximos :days dias',
    'on_date_suffix' => ' a :date',
    'shortfall_window' => ':count janela de défice ativa|:count janelas de défice ativas',
    'lowest_in_30_label' => 'Mínimo em 30 dias',
    'next_ics' => 'Próxima liquidação ICS: :amount a :date',
    'ics_overdue' => 'Liquidação ICS em atraso: :amount, vencia a :date',

    'stale_run' => 'Projetado a partir de :date — não atualizado desde então.',

    'confidence' => [
        'high' => 'Alta',
        'medium' => 'Média',
        'low' => 'Baixa',
    ],

    'errors' => [
        'amount_required' => 'O valor é obrigatório.',
        'amount_decimals' => 'O valor deve ser um número com no máximo :decimals casa decimal.|O valor deve ser um número com no máximo :decimals casas decimais.',
        'amount_whole' => 'O valor deve ser um número inteiro — esta moeda não tem unidade menor.',
        'amount_non_negative' => 'O valor deve ser zero ou positivo.',
        'amount_non_zero' => 'O valor não pode ser zero.',
        'field_required' => 'O campo :field é obrigatório.',
    ],
];
