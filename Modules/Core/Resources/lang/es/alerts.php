<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Alertas del sistema',

    'actions' => [
        'install_next_launch' => 'Instalar en el próximo inicio',
        'install_next_launch_aria' => 'Instalar en el próximo inicio — marca la alerta del sistema n.º :id como resuelta',
        'skip_version' => 'Omitir esta versión',
        'release_notes' => 'Notas de la versión →',
        'update_now' => 'Actualizar ahora',
        'update_now_aria' => 'Actualizar ahora — marca la alerta del sistema n.º :id como resuelta',
        'remind_later' => 'Recordármelo más tarde',
        'mark_resolved' => 'Marcar como resuelta',
        'mark_resolved_aria' => 'Marcar como resuelta — alerta del sistema n.º :id',
    ],

    'messages' => [
        'update_available' => 'Actualización disponible — Beatrax :version está lista. Se instalará en el próximo inicio.',
        'update_stale' => 'Tienes la versión :current — la versión :latest lleva 30 días disponible. Actualiza ahora.',
        'update_critical' => 'Actualización crítica disponible — la versión :version corrige :summary. Instálala cuanto antes.',
        'backup_corrupt_with_path' => 'La copia de seguridad escrita el :timestamp no ha superado la comprobación de integridad. Revisa :path. Resuélvelo antes de confiar en las copias de seguridad.',
        'backup_corrupt_no_path' => 'La copia de seguridad iniciada el :timestamp se interrumpió antes de generar ningún archivo: la base de datos de origen no superó la comprobación de integridad. Resuélvelo antes de confiar en las copias de seguridad.',

        'backup_overdue' => 'La copia de seguridad verificada más reciente tiene :hoursh de antigüedad. Ejecuta <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> o espera a la ejecución programada de las 03:00.',
        'wal_mode_missing' => 'SQLite no está en modo WAL (actualmente :mode). Las escrituras simultáneas pueden bloquearse. Ejecuta <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> para obtener ayuda.',
        'synchronous_misconfigured' => 'El nivel synchronous de SQLite es :level (se esperaba NORMAL/1). La durabilidad puede comportarse de forma distinta a la configurada. Ejecuta <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> para obtener ayuda.',
        'reconnect_link' => 'Volver a conectar →',
    ],
];
