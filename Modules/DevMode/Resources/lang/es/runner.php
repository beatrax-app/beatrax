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
        'invalid_args' => 'No se puede ejecutar :command — :reason',
        'arg' => 'argumento|argumentos',
        'started' => 'Iniciado :command (ejecución :runId)',
        'run_expired' => 'El registro de la ejecución ha caducado — no se puede reejecutar.',
        'reran' => 'Reejecutado :command (ejecución :runId)',
        'rerun_forbidden' => 'Esa ejecución pertenece a otro desarrollador.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Copiar la base de datos', 'description' => 'Escribe una copia SQLite con marca de tiempo en la carpeta de copias de seguridad (o en la ruta indicada).'],
        'doctor' => ['label' => 'Ejecutar doctor', 'description' => 'Informa de las versiones instaladas de PHP / Composer / SQLite y comprueba los mínimos.'],
        'failed_jobs' => ['label' => 'Depurar los trabajos fallidos', 'description' => 'Depura las entradas ya resueltas de la tabla failed_jobs que gestiona Laravel.'],
        'cache_clear' => ['label' => 'Vaciar la caché', 'description' => 'Vacía el almacén de caché de la aplicación.'],
        'route_list' => ['label' => 'Listar las rutas', 'description' => 'Imprime en la salida estándar todas las rutas HTTP registradas.'],
        'config_show' => ['label' => 'Mostrar la configuración', 'description' => 'Imprime el valor de la clave de configuración indicada.'],
        'view_clear' => ['label' => 'Vaciar la caché de vistas', 'description' => 'Vacía la caché de vistas Blade compiladas.'],
        'queue_retry' => ['label' => 'Reintentar los trabajos fallidos', 'description' => 'Reintenta un trabajo (por id) o todos los fallidos (id vacío).'],
        'rederive_fingerprints' => ['label' => 'Recalcular las huellas', 'description' => 'Vuelve a calcular la huella de cada transacción con la versión de normalización actual.'],
        'db_restore' => ['label' => 'Restaurar la base de datos', 'description' => 'Sustituye la base de datos actual por el archivo de copia de seguridad indicado.'],
        'migrate_fresh' => ['label' => 'Eliminar las tablas y volver a migrar', 'description' => 'Elimina todas las tablas y vuelve a ejecutar todas las migraciones.'],
        'reset_password' => ['label' => 'Restablecer la contraseña', 'description' => 'Restablece la contraseña de un usuario de forma interactiva (rechaza el uso no interactivo).'],
        'regenerate_recovery_codes' => ['label' => 'Regenerar los códigos de recuperación', 'description' => 'Regenera los 10 códigos de recuperación de un solo uso de un usuario.'],
        'grant_dev' => ['label' => 'Conceder acceso de desarrollador', 'description' => 'Pone is_developer=true para el usuario indicado.'],
        'install' => ['label' => 'Ejecutar la instalación', 'description' => 'Configuración inicial idempotente. Volver a ejecutarla en una instalación ya configurada es destructivo.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Archivo de destino', 'help' => 'Déjalo en blanco para usar la carpeta de copias de seguridad por defecto.', 'placeholder' => '/ruta/a/backup.sqlite (opcional)'],
        'action' => ['label' => 'Acción'],
        'config' => ['label' => 'Clave de configuración', 'help' => 'El archivo de configuración o la clave con puntos que quieres imprimir, por ejemplo `app` o `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id del trabajo', 'help' => 'Déjalo en blanco para reintentar todos los trabajos fallidos; indica un id para reintentar solo uno.', 'placeholder' => 'todos (o un id concreto)'],
        'queue' => ['label' => 'Nombre de la cola', 'help' => 'Filtro de cola opcional; por defecto, todas las colas.', 'placeholder' => 'default'],
        'from' => ['label' => 'Ruta del archivo de copia de seguridad', 'help' => 'Sustituye la base de datos actual por el archivo que haya en la ruta indicada.', 'placeholder' => '/ruta/a/backup.sqlite'],
        'username' => ['label' => 'Nombre de usuario', 'placeholder' => 'alice'],
    ],
];
