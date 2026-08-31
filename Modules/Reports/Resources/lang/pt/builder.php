<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Sem categoria',
    'no_counterparty' => 'Sem contraparte',
    'unavailable_counterparty' => 'Contraparte não está neste dispositivo',
    'title' => 'Relatórios',
    'page_title' => 'Relatórios · Beatrax',
    'subtitle' => 'Compõe um relatório a partir do teu livro-razão.',
    'controls_aria' => 'Controlos do relatório',
    'result_aria' => 'Resultado do relatório',
    'dismiss' => 'Dispensar',

    'metric' => [
        'heading' => 'Métrica',
        'spend' => 'Despesa',
        'income' => 'Receita',
        'net' => 'Líquido',
        'net_worth' => 'Património líquido',
        'fallback' => 'Montante',
    ],

    'group_by' => 'Agrupar por',

    'dimension' => [
        'category' => 'Categoria',
        'time_bucket' => 'Intervalo de tempo',
        'counterparty' => 'Contraparte',
        'account' => 'Conta',
    ],

    'period' => [
        'heading' => 'Período',
        'this_month' => 'Este mês',
        'last_3_months' => 'Últimos 3 meses',
        'last_6_months' => 'Últimos 6 meses',
        'last_12_months' => 'Últimos 12 meses',
        'ytd' => 'Desde o início do ano',
        'this_year' => 'Este ano',
        'custom' => 'Intervalo personalizado',
        'from' => 'De',
        'to' => 'Até',
        'error' => [
            'incomplete' => 'Escolha uma data de início e uma de fim.',
            'malformed' => 'Use uma data válida no formato AAAA-MM-DD.',
            'inverted' => 'A data de fim é anterior à de início.',
        ],
    ],

    'currency' => [
        'heading' => 'Moeda',
        'aria' => 'Modo de moeda',
        'base' => 'Base',
        'original' => 'Original',
    ],

    'granularity' => [
        'heading' => 'Granularidade',
        'aria' => 'Granularidade temporal',
        'monthly' => 'Mensal',
        'weekly' => 'Semanal',
    ],

    'filters' => [
        'heading' => 'Filtros',
        'net_worth_note' => 'O património líquido é um saldo: só se aplica o filtro de conta.',
    ],

    'compare' => 'Comparar com o período anterior',

    'viz' => [
        'heading' => 'Visualização',
        'table' => 'Tabela',
        'bar' => 'Barras',
        'line' => 'Linhas',
        'donut' => 'Anel',
    ],

    'actions' => [
        'update_report' => 'Atualizar relatório',
        'save_report' => 'Guardar relatório',
        'report_name' => 'Nome do relatório',
        'update' => 'Atualizar',
        'save' => 'Guardar',
        'cancel' => 'Cancelar',
        'export_csv' => 'Exportar CSV',
    ],

    'updating' => '… A atualizar',

    'empty' => [
        'heading' => 'Não há nada para mostrar nesta seleção',
        'body' => 'Experimenta alargar o intervalo de datas ou remover um filtro.',
    ],

    'total_prefix' => 'Total',
    'total' => 'Total',
    'vs_previous' => 'face ao período anterior',
    'view_transactions' => 'Ver transações',

    'fx_excluded' => ':count conta não convertida — não há taxa disponível|:count contas não convertidas — não há taxa disponível',

    'group_header' => [
        'category' => 'Categoria',
        'counterparty' => 'Contraparte',
        'account' => 'Conta',
        'month' => 'Mês',
        'default' => 'Grupo',
    ],

    'chart' => [
        'other_currencies' => 'Gráfico em :currency — :list não representado',
        'undrawn' => 'Fora do anel — :amount segue no sentido contrário',
        'bar_title' => 'Clica numa barra para ver as suas transações',
        'line_title' => 'Clica num ponto para ver as suas transações',
        'donut_title' => 'Clica num segmento para ver as suas transações',
    ],

    'flash' => [
        'saved' => 'Relatório guardado.',
        'updated' => 'Relatório atualizado.',
    ],

    'filter' => [
        'account' => 'Conta',
        'account_count' => ':count conta|:count contas',
        'remove_account' => 'Remover o filtro de conta',
        'account_dialog' => 'Filtro de conta',

        'category' => 'Categoria',
        'category_count' => ':count categoria|:count categorias',
        'remove_category' => 'Remover o filtro de categoria',
        'category_dialog' => 'Filtro de categoria',

        'counterparty' => 'Contraparte',
        'counterparty_count' => ':count contraparte|:count contrapartes',
        'remove_counterparty' => 'Remover o filtro de contraparte',
        'counterparty_dialog' => 'Filtro de contraparte',

        'amount' => 'Montante',
        'remove_amount' => 'Remover o filtro de montante',
        'amount_dialog' => 'Filtro de montante',
        'dir_both' => 'Ambos',
        'dir_in' => 'Entrada',
        'dir_out' => 'Saída',
        'min' => 'Mín',
        'max' => 'Máx',
        'min_aria' => 'Montante mínimo',
        'max_aria' => 'Montante máximo',
    ],

    'other_movement' => 'Taxas e ajustes (não contados acima)',
    'other_movement_with_refunds' => 'Taxas, reembolsos e ajustes (não contados acima)',
];
