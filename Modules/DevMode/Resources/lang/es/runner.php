<?php

declare(strict_types=1);

return [
    'heading' => 'Runner de Artisan',
    'subtitle' => 'Ejecuta comandos SAFE con un clic; los comandos DESTRUCTIVE pasan por la triple verificación.',
    'run_a_command' => 'Ejecutar un comando',
    'filter_aria' => 'Filtro de ejecuciones',
    'filter' => [
        'all' => 'Todas',
        'running' => 'En curso',
        'failed' => 'Fallidas',
        'destructive' => 'Destructivas',
    ],
    'worker_running' => 'Worker de la cola: EN MARCHA',
    'worker_not_running' => 'Worker de la cola: DETENIDO',
    'no_runs' => 'Aún no hay ejecuciones. Haz clic en "Ejecutar un comando" o usa la paleta de comandos (⌘K).',
    'recent_runs_aria' => 'Ejecuciones recientes',
    'modal_heading' => 'Ejecutar un comando SAFE',
    'modal_intro' => 'Elige un comando de nivel SAFE para ejecutarlo ahora mismo. Los comandos DESTRUCTIVE no aparecen aquí: usa la opción Reejecutar de la cronología o la paleta ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Abre un formulario de argumentos',

    'spawning_unavailable' => 'Los comandos de Artisan se ejecutan en un proceso aparte, y esta plataforma no deja que la app inicie ninguno. Ejecútalos desde la app de escritorio.',

    'status' => [
        'running' => 'En curso',
        'done' => 'Hecho',
        'failed' => 'Fallido',
        'cancelled' => 'Cancelado',
    ],
    'cancel' => 'Cancelar',
    'rerun' => 'Reejecutar',
    'started' => 'Iniciado :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Comando desconocido: :command',
        'missing_args' => 'No se puede ejecutar :command — :noun sin indicar: :list',
        'arg' => 'argumento|argumentos',
        'started' => 'Iniciado :command (ejecución :runId)',
        'run_expired' => 'El registro de la ejecución ha caducado — no se puede reejecutar.',
        'reran' => 'Reejecutado :command (ejecución :runId)',
    ],
];
