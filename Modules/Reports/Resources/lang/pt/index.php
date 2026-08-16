<?php

declare(strict_types=1);

return [
    'title' => 'Relatórios',
    'page_title' => 'Relatórios · Beatrax',
    'saved_report' => 'relatório guardado|relatórios guardados',
    'pinned_count' => 'fixados',
    'dismiss' => 'Dispensar',

    'build_new' => 'Criar um novo relatório',
    'view_mode_aria' => 'Modo de visualização',
    'cards' => 'Cartões',
    'list' => 'Lista',

    'empty' => [
        'heading' => 'Ainda não há relatórios guardados',
        'body' => 'Cria um aqui em baixo e guarda-o para o veres aqui.',
        'cta' => 'Cria o teu primeiro relatório →',
    ],

    'pin' => [
        'pinned_aria' => 'Fixado — desafixar do painel',
        'pin_aria' => 'Fixar — fixar no painel',
        'pinned_title' => 'Fixado',
        'pin_title' => 'Fixar no painel',
        'pinned_label' => 'Fixado',
        'pin_label' => 'Fixar',
    ],

    'open' => 'Abrir',
    'edit' => 'Editar',

    'delete_confirm' => 'Eliminar «:name»?',
    'delete_report' => 'Eliminar o relatório',
    'cancel' => 'Cancelar',
    'delete' => 'Eliminar',
    'delete_aria' => 'Eliminar :name',

    'col' => [
        'name' => 'Nome',
        'summary' => 'Resumo',
        'pinned' => 'Fixado',
        'actions' => 'Ações',
    ],

    'flash' => [
        'not_found' => 'Relatório não encontrado (pode ter sido eliminado noutro separador).',
        'deleted' => 'Relatório eliminado.',
    ],
    'pin_cap' => 'Podes fixar até 3 relatórios. Desafixa um para adicionares este.',

    'summary' => [
        'metric' => [
            'spend' => 'Despesa',
            'income' => 'Receita',
            'net' => 'Líquido',
            'net_worth' => 'Património líquido',
            'fallback' => 'Montante',
        ],
        'dimension' => [
            'category' => 'categoria',
            'time_bucket' => 'período',
            'counterparty' => 'contraparte',
            'account' => 'conta',
            'fallback' => 'categoria',
        ],
        'period' => [
            'this_month' => 'Este mês',
            'last_3_months' => 'Últimos 3 meses',
            'last_6_months' => 'Últimos 6 meses',
            'last_12_months' => 'Últimos 12 meses',
            'ytd' => 'Desde o início do ano',
            'this_year' => 'Este ano',
            'custom' => 'Intervalo personalizado',
        ],
        'with_dimension' => ':metric · por :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
