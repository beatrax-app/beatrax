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
        'assign_in_budgets' => 'Asignar en Presupuestos',
        'dismiss' => 'Descartar',
        'dismiss_aria' => 'Descartar — alerta del sistema n.º :id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'los avisos de presupuesto',
        'daily-triggers' => 'los recordatorios diarios y el resumen',
    ],

    'messages' => [
        'update_available' => 'Actualización disponible — Beatrax :version está lista. Se instalará en el próximo inicio.',
        'update_stale' => 'Tienes la versión :current — la versión :latest lleva 30 días disponible. Actualiza ahora.',
        'update_critical' => 'Actualización crítica disponible — la versión :version corrige :summary. Instálala cuanto antes.',
        'backup_corrupt_with_path' => 'La copia de seguridad escrita el :timestamp no ha superado la comprobación de integridad. Revisa :path. Resuélvelo antes de confiar en las copias de seguridad.',
        'backup_corrupt_no_path' => 'La copia de seguridad iniciada el :timestamp se interrumpió antes de generar ningún archivo: la base de datos de origen no superó la comprobación de integridad. Resuélvelo antes de confiar en las copias de seguridad.',
        'backup_write_failed' => 'La copia de seguridad iniciada a las :timestamp no se completó: la base de datos pasó sus comprobaciones, pero no se pudieron escribir sus archivos. Comprueba el espacio libre y los permisos de la carpeta de copias.',
        'backup_restore_failed' => 'La restauración iniciada a las :timestamp no se completó. Tus datos anteriores se guardaron antes en :snapshot.',

        'backup_overdue' => 'La copia de seguridad verificada más reciente tiene :hoursh de antigüedad. Beatrax hace esta copia solo, una vez al día, mientras la app está abierta — no hay nada que ejecutar a mano. Si sigue con esta antigüedad, la app no ha estado abierta cuando tocaba la ejecución diaria.',
        'backup_none_found' => 'No se ha encontrado ninguna copia de seguridad verificada en la carpeta de copias. Beatrax hace esta copia solo, una vez al día, mientras la app está abierta — no hay nada que ejecutar a mano.',
        'wal_mode_missing' => 'SQLite no está en modo WAL (actualmente :mode). Las escrituras simultáneas pueden bloquearse. Ejecuta <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> para obtener ayuda.',
        'synchronous_misconfigured' => 'El nivel synchronous de SQLite es :level (se esperaba NORMAL/1). La durabilidad puede comportarse de forma distinta a la configurada. Ejecuta <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> para obtener ayuda.',
        'oauth_scrub_set_failed' => 'La ocultación de secretos de OAuth está fuera de servicio. Los registros y los extractos de auditoría pueden contener tokens sin ocultar hasta la próxima carga correcta.',
        'oauth_reauth_required' => 'Los secretos de OAuth se han movido al almacenamiento por usuario. Vuelve a autorizar Gmail y Microsoft para reanudar el análisis del correo. El archivo de secretos anterior se renombró a :file para poder revertir.',
        'oauth_reconsent' => 'Vuelve a conectar tu :provider',
        'auth_recovery_code_consumed' => 'Código de recuperación usado por :username.',
        'auth_recovery_code_failed' => 'Intento fallido de código de recuperación para :username.',
        'auth_lock_hard_cap_reached' => 'Sesión cerrada tras demasiados intentos fallidos de PIN.',
        'open_banking_reconsent' => 'Vuelve a conectar tu banco',
        'open_banking_nothing_imported' => 'Tu banco ha enviado transacciones, pero Beatrax no ha podido registrar ninguna, así que no ha llegado nada a tu registro. Abre los ajustes de Open banking para ver por qué.',
        'auth_lock_corrupted_key' => 'Tu PIN no puede abrir el bloqueo de la aplicación en este dispositivo: la clave guardada no se puede leer. Inicia sesión con la contraseña de tu cuenta para establecer un PIN nuevo.',
        'sync_gdk_rewrap_failed' => 'Ha fallado el reempaquetado del llavero GDK tras cambiar la frase de contraseña del bloqueo de la aplicación: los datos cifrados podrían ser irrecuperables hasta que se reempaquete el llavero.',
        'worker_crashed' => 'El procesamiento en segundo plano de Beatrax se detuvo inesperadamente. Las importaciones y los análisis de correo están en pausa. Vuelve a abrir la aplicación para reiniciarlo.',
        'auth_lock_key_material_stranded' => 'El cifrado en reposo está activo para esta cuenta, pero ninguna envoltura del bloqueo de la aplicación conserva ya la clave de datos, por lo que cada nota, descripción y dato de contraparte cifrado se lee como vacío. Emparejar con un dispositivo que aún tenga la clave es la única vuelta atrás.',
        'auth_lock_recovery_wrap_stale' => 'La contraseña de la cuenta cambió sin que se reempaquetara la envoltura de recuperación del bloqueo de la aplicación, así que esa contraseña ya no abre el bloqueo. El PIN sí. Vuelve a vincular la contraseña de la cuenta desde los ajustes del bloqueo mientras aún conozcas el PIN; de lo contrario, un PIN olvidado no deja nada detrás.',
        'reconnect_link' => 'Volver a conectar →',
        'pots_category_link_retired' => 'El presupuesto por sobres ha sustituido a las huchas vinculadas a una categoría. :amount de :count hucha archivada vuelve a estar sin asignar y espera a que lo asignes.|El presupuesto por sobres ha sustituido a las huchas vinculadas a una categoría. :amount de :count huchas archivadas vuelve a estar sin asignar y espera a que lo asignes.',
        'notifications_deferred_pass_failed' => 'Beatrax no pudo calcular :pass en este dispositivo, así que puede que falten algunos. Lo intenta de nuevo cada vez que abres la aplicación.',
    ],
];
