<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Escreve para pesquisar vistas, comandos e ações. Prime Esc para fechar.',
    'search_aria' => 'Escreve para pesquisar vistas, comandos e ações',
    'dialog_aria' => 'Paleta de comandos',
    'token_suggest_aria' => 'Sugestões de tokens',
    'rail_view' => 'Vista',
    'rail_dev' => 'Dev',
    'rail_action' => 'Ação',
    'rail_recent' => 'Recentes',
    'no_recent' => 'Ainda não há escolhas recentes.',
    'section_transactions' => 'Transações',
    'section_counterparties' => 'Contrapartes',
    'section_categories' => 'Categorias',
    'section_goals_recurring' => 'Objetivos e recorrentes',
    'no_name' => '(sem nome)',
    'see_all' => 'Ver :count resultado →|Ver todos os :count resultados →',
    'no_transactions' => 'Nenhuma transação corresponde a ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'contraparte',
    'source_category' => 'categoria',
    'results_aria' => 'Resultados',
    'no_results' => 'Sem resultados.',
    'foot_navigate' => 'navegar',
    'foot_select' => 'selecionar',
    'foot_close' => 'fechar',
    'close_aria' => 'Fechar a pesquisa',
    'close_caption' => 'Fechar',
    'foot_try' => 'Experimenta',
    'results' => ':count resultado|:count resultados',

    'action' => [
        'run_import' => ['label' => 'Executar uma importação', 'hint' => 'Abrir o assistente de importação'],
        'scan_email' => ['label' => 'Analisar o e-mail agora', 'hint' => 'Executar já a sincronização da caixa de correio'],
        'open_profile' => ['label' => 'Abrir o perfil', 'hint' => 'Definições — conta e preferências'],
        'toggle_theme' => ['label' => 'Mudar o tema', 'hint' => 'Alternar entre o tema claro e o escuro'],
    ],

    'run_command' => 'Executar :command',

    'nav' => [
        'overview' => ['label' => 'Visão geral de desenvolvimento', 'hint' => 'Cartões do sistema + execuções recentes'],
        'artisan' => ['label' => 'Runner do Artisan', 'hint' => 'Executar comandos autorizados'],
        'audit' => ['label' => 'Registo de auditoria de desenvolvimento', 'hint' => 'Todas as ações do modo de programador'],
        'logs' => ['label' => 'Leitor de registos', 'hint' => 'Fluxo em direto de laravel-*.log'],
        'queue' => ['label' => 'Inspetor de filas', 'hint' => 'Pendentes / falhadas / lotes'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Sondas do sistema'],
        'sql' => ['label' => 'Painel SQL', 'hint' => 'Explorador apenas com SELECT'],
        'system' => ['label' => 'Instantâneo do sistema', 'hint' => 'Ambiente + caminhos + configuração'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Painel de filas integrado'],
        'sync_health' => ['label' => 'Estado da sincronização', 'hint' => 'Operações de fusão em quarentena ou ignoradas'],
    ],
];
