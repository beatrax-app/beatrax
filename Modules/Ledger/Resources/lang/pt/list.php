<?php

declare(strict_types=1);

return [
    'page_title' => 'Transações',
    'heading' => 'Transações',

    'subtitle_searching' => 'A pesquisar em todo o histórico',
    'subtitle_full' => 'Histórico completo.',
    'subtitle_recent' => 'Transações recentes (últimos 90 dias).',

    'currency_aria' => 'Vista por moeda',
    'currency_eur' => 'Só EUR',
    'currency_original' => 'Moeda original',

    'show_recent' => 'Mostrar só as recentes',
    'show_full' => 'Mostrar o histórico completo',

    'empty_period' => 'Não há nada neste período.',

    'loading_more' => 'A carregar mais transações',
    'load_more' => 'Carregar mais',

    'split_badge' => 'Divisão · :count',
    'split_expand_aria' => 'Dividida por :count categoria — expande para veres|Dividida por :count categorias — expande para veres',

    'chain_badge' => 'cadeia',
    'chain_title' => 'Faz parte de uma cadeia — abre esta linha para veres',

    'table' => [
        'date' => 'Data',
        'counterparty' => 'Contraparte',
        'category' => 'Categoria',
        'tax' => 'Impostos',
        'status' => 'Estado',
        'amount' => 'Montante',
    ],

    'search' => [
        'placeholder' => 'Pesquisar comerciante, descrição, notas…',
        'placeholder_short' => 'Pesquisar transações…',
        'aria' => 'Pesquisar transações',
        'clear_all' => 'Limpar tudo',
        'filters' => 'Filtros',
        'open_filters_aria' => 'Abrir filtros',
        'apply' => 'Aplicar',
        'clear' => 'Limpar',

        'count' => ':count transação|:count transações',
        'matching_suffix' => 'correspondem aos filtros',
        'flow' => ':out a sair / :in a entrar',
    ],

    'no_results' => [
        'heading' => 'Nada corresponde',
        'remove_prompt' => 'Experimenta remover um filtro que possa estar a limitar os resultados:',
        'no_match_query' => 'Nenhuma transação de todo o histórico corresponde a “:query”.',
        'no_match_filters' => 'Nenhuma transação corresponde aos filtros aplicados.',
        'did_you_mean' => 'Querias dizer:',
        'account_fallback' => 'Conta :id',
        'category_fallback' => 'Categoria :id',
    ],

    'filter' => [
        'date' => 'Data',
        'account' => 'Conta',
        'amount' => 'Montante',
        'category' => 'Categoria',
        'date_range' => 'Intervalo de datas',
        'from' => 'De',
        'to' => 'Até',
        'custom_range' => 'Intervalo personalizado ×',
        'after' => 'Depois de :date ×',
        'before' => 'Antes de :date ×',
        'dir_both' => 'Ambos',
        'dir_in' => 'Entradas',
        'dir_out' => 'Saídas',
        'min' => 'Mín.',
        'max' => 'Máx.',
        'min_aria' => 'Montante mínimo',
        'max_aria' => 'Montante máximo',
        'after_aria' => 'Depois da data',
        'before_aria' => 'Antes da data',
        'acct' => ':count conta|:count contas',
        'cat' => ':count categoria|:count categorias',
        'date_dialog' => 'Filtro de data',
        'account_dialog' => 'Filtro de conta',
        'amount_dialog' => 'Filtro de montante',
        'category_dialog' => 'Filtro de categoria',
        'remove_date_aria' => 'Remover o filtro de data',
        'remove_account_aria' => 'Remover o filtro de conta',
        'remove_amount_aria' => 'Remover o filtro de montante',
        'remove_category_aria' => 'Remover o filtro de categoria',

        'remove_named_aria' => 'Remover o filtro :name',
    ],

    'date_preset' => [
        'this_month' => 'Este mês',
        'last_month' => 'Mês passado',
        'this_year' => 'Este ano',
        'last_year' => 'Ano passado',
    ],
];
