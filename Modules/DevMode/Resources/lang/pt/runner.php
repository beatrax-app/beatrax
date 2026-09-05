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
    // i18n-review: pt · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Ainda não há execuções. Toca em "Executar um comando" ou usa a paleta de comandos (⌘K).',
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
        'invalid_args' => 'Não é possível executar :command — :reason',
        'arg' => 'argumento|argumentos',
        'started' => 'Iniciado :command (execução :runId)',
        'run_expired' => 'O registo da execução expirou — não é possível repetir.',
        'reran' => 'Repetido :command (execução :runId)',
        'rerun_forbidden' => 'Essa execução pertence a outro programador.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Fazer cópia da base de dados', 'description' => 'Escreve uma cópia SQLite com data e hora na pasta das cópias de segurança.'],
        'doctor' => ['label' => 'Executar o doctor', 'description' => 'Indica as versões instaladas de PHP / Composer / SQLite e verifica os mínimos.'],
        'failed_jobs' => ['label' => 'Limpar as tarefas falhadas', 'description' => 'Limpa as entradas já resolvidas da tabela failed_jobs gerida pelo Laravel.'],
        'cache_clear' => ['label' => 'Limpar a cache', 'description' => 'Esvazia a cache da aplicação.'],
        'route_list' => ['label' => 'Listar as rotas', 'description' => 'Imprime na saída padrão todas as rotas HTTP registadas.'],
        'config_show' => ['label' => 'Mostrar a configuração', 'description' => 'Imprime o valor da chave de configuração indicada.'],
        'view_clear' => ['label' => 'Limpar a cache das vistas', 'description' => 'Esvazia a cache das vistas Blade compiladas.'],
        'queue_retry' => ['label' => 'Repetir as tarefas falhadas', 'description' => 'Repete uma tarefa (por id) ou todas as tarefas falhadas (id vazio).'],
        'rederive_fingerprints' => ['label' => 'Recalcular as impressões digitais', 'description' => 'Recalcula a impressão digital de cada transação com a versão de normalização atual.'],
        'db_restore' => ['label' => 'Restaurar a base de dados', 'description' => 'Substitui a base de dados atual pelo ficheiro de cópia de segurança indicado.'],
        'regenerate_recovery_codes' => ['label' => 'Gerar novos códigos de recuperação', 'description' => 'Gera de novo os 10 códigos de recuperação de utilização única de um utilizador.'],
        'grant_dev' => ['label' => 'Conceder acesso de programador', 'description' => 'Define is_developer=true para o utilizador indicado.'],
        'install' => ['label' => 'Executar a instalação', 'description' => 'Configuração inicial idempotente. Voltar a executá-la numa instalação já configurada é destrutivo.'],
    ],

    'arg' => [
        'action' => ['label' => 'Ação'],
        'config' => ['label' => 'Chave de configuração', 'help' => 'O ficheiro de configuração ou a chave com pontos a imprimir, por exemplo `app` ou `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id da tarefa', 'help' => 'Deixa em branco para repetir todas as tarefas falhadas; indica um id para repetir apenas uma.', 'placeholder' => 'todas (ou um id específico)'],
        'queue' => ['label' => 'Nome da fila', 'help' => 'Filtro de fila opcional; por omissão, todas as filas.', 'placeholder' => 'default'],
        'path' => ['label' => 'Caminho do ficheiro de cópia de segurança', 'help' => 'Substitui a base de dados atual pelo ficheiro que estiver no caminho indicado.', 'placeholder' => '/caminho/para/backup.sqlite'],
        'username' => ['label' => 'Nome de utilizador', 'placeholder' => 'alice'],
    ],
];
