<?php

declare(strict_types=1);

return [
    'heading' => 'Runner do Artisan',
    'subtitle' => 'Executa comandos SAFE com um clique; os comandos DESTRUCTIVE passam pela verificação tripla.',
    'run_a_command' => 'Executar um comando',
    'filter_aria' => 'Filtro de execuções',
    'filter' => [
        'all' => 'Todas',
        'running' => 'A decorrer',
        'failed' => 'Falhadas',
        'destructive' => 'Destrutivas',
    ],
    'worker_running' => 'Worker da fila: A CORRER',
    'worker_not_running' => 'Worker da fila: PARADO',
    'no_runs' => 'Ainda não há execuções. Clica em "Executar um comando" ou usa a paleta de comandos (⌘K).',
    'recent_runs_aria' => 'Execuções recentes',
    'modal_heading' => 'Executar um comando SAFE',
    'modal_intro' => 'Escolhe um comando de nível SAFE para executar já. Os comandos DESTRUCTIVE não aparecem aqui — usa a opção Repetir da cronologia ou a paleta ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Abre um formulário de argumentos',

    'spawning_unavailable' => 'Os comandos Artisan correm num processo separado, e esta plataforma não deixa a app iniciar nenhum. Executa-os na app de computador.',

    'status' => [
        'running' => 'A decorrer',
        'done' => 'Concluído',
        'failed' => 'Falhou',
        'cancelled' => 'Cancelado',
    ],
    'cancel' => 'Cancelar',
    'rerun' => 'Repetir',
    'started' => 'Iniciado :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Comando desconhecido: :command',
        'missing_args' => 'Não é possível executar :command — faltam :noun: :list',
        'arg' => 'argumento|argumentos',
        'started' => 'Iniciado :command (execução :runId)',
        'run_expired' => 'O registo da execução expirou — não é possível repetir.',
        'reran' => 'Repetido :command (execução :runId)',
    ],
];
