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
        'oauth_scrub_set_failed' => 'La ocultación de secretos de OAuth está fuera de servicio. Los registros y los extractos de auditoría pueden contener tokens sin ocultar hasta la próxima carga correcta.',
        'oauth_reauth_required' => 'Los secretos de OAuth se han movido al almacenamiento por usuario. Vuelve a autorizar Gmail y Microsoft para reanudar el análisis del correo. El archivo de secretos anterior se renombró a :file para poder revertir.',
        'oauth_reconsent' => 'Vuelve a conectar tu :provider',
        'auth_recovery_code_consumed' => 'Código de recuperación usado por :username.',
        'auth_recovery_code_failed' => 'Intento fallido de código de recuperación para :username.',
        'auth_lock_hard_cap_reached' => 'Sesión cerrada tras demasiados intentos fallidos de PIN.',
        'open_banking_reconsent' => 'Vuelve a conectar tu banco',
        'auth_lock_corrupted_key' => 'Tu PIN no puede abrir el bloqueo de la aplicación en este dispositivo: la clave guardada no se puede leer. Inicia sesión con la contraseña de tu cuenta para establecer un PIN nuevo.',
        'sync_gdk_rewrap_failed' => 'Ha fallado el reempaquetado del llavero GDK tras cambiar la frase de contraseña del bloqueo de la aplicación: los datos cifrados podrían ser irrecuperables hasta que se reempaquete el llavero.',
        'worker_crashed' => 'El procesamiento en segundo plano de Beatrax se detuvo inesperadamente. Las importaciones y los análisis de correo están en pausa. Vuelve a abrir la aplicación para reiniciarlo.',
        'auth_lock_key_material_stranded' => 'El cifrado en reposo está activo para esta cuenta, pero ninguna envoltura del bloqueo de la aplicación conserva ya la clave de datos, por lo que cada nota, descripción y dato de contraparte cifrado se lee como vacío. Emparejar con un dispositivo que aún tenga la clave es la única vuelta atrás.',
        'auth_lock_recovery_wrap_stale' => 'La contraseña de la cuenta cambió sin que se reempaquetara la envoltura de recuperación del bloqueo de la aplicación, así que esa contraseña ya no abre el bloqueo. El PIN sí. Vuelve a vincular la contraseña de la cuenta desde los ajustes del bloqueo mientras aún conozcas el PIN; de lo contrario, un PIN olvidado no deja nada detrás.',
        'reconnect_link' => 'Volver a conectar →',
    ],
];
