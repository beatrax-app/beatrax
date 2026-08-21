<?php

declare(strict_types=1);

return [
    'heading' => 'Dispositivos y sincronización',

    'enable_sync' => 'Activar la sincronización',
    'enable_sync_help' => 'Comparte tus datos de forma segura entre dispositivos de confianza. Requiere un bloqueo de la app.',

    'app_lock_notice' => 'Define primero un bloqueo de la app para activar la sincronización.',
    'go_to_app_lock' => 'Ir a Bloqueo de la app',

    'encrypted_at_rest' => 'Datos cifrados en reposo',
    'encrypted_at_rest_scope' => 'Las notas, las descripciones de las transacciones y los nombres e IBAN de a quién pagas están cifrados con la contraseña de bloqueo de la app. Los importes, las fechas y el nombre e IBAN de tu propia cuenta no lo están, y algunos nombres de comercios siguen apareciendo en texto claro en otras partes del archivo de base de datos.',
    'on' => 'Activado',
    'securing' => 'Protegiendo tus datos…',
    'do_not_close' => 'No cierres esta ventana.',
    'encryption_progress_aria' => 'Progreso del cifrado',
    'not_encrypted_offer' => 'Tus datos no están cifrados en reposo. El cifrado oculta a quién pagas si pierdes o te roban este dispositivo: los importes, las fechas y el índice de búsqueda siguen siendo legibles.',
    'enable_encryption' => 'Activar el cifrado',

    'your_devices' => 'Tus dispositivos',

    'moved_help' => 'La vinculación, los nombres de dispositivo y el cifrado están ahora junto a tu estado de sincronización.',
    'moved_cta' => 'Abrir Sincronización y dispositivo',
    'device_name' => 'Nombre del dispositivo',
    'save' => 'Guardar',
    'peer_default_name' => 'Dispositivo vinculado',
    'rename_device' => 'Cambiar el nombre del dispositivo',
    'this_device' => 'Este dispositivo',
    'removed' => 'Eliminado',
    'confirmed' => 'Confirmado',
    'awaiting_confirmation' => 'Esperando confirmación',
    'safety_number_words' => 'Palabras del número de seguridad:',
    'paired' => 'Vinculado',
    'remove_aria' => 'Eliminar :name',
    'remove' => 'Eliminar',
    'pair_new_device' => 'Vincular un dispositivo nuevo',

    'relay_endpoint' => 'Endpoint del relay',
    'relay_endpoint_help' => 'Opcional. Si se define, los dispositivos sin conexión se sincronizan a través de este relay. Déjalo vacío para usar solo LAN&#8209;directa.',
    'relay_endpoint_aria' => 'URL del endpoint del relay',
    'relay_insecure_warning' => 'Este endpoint de relay usa HTTP sin cifrar. Aunque el relay nunca descifra tus datos, una conexión insegura expone los tamaños cifrados y los tiempos a quien observe la red. Usa un endpoint <strong>https://</strong> para tener la mejor privacidad.',

    'enable_at_rest' => 'Activar el cifrado en reposo',
    'enable_at_rest_body' => 'Tus datos se cifrarán con la contraseña del bloqueo de la app. Se creará automáticamente una copia de seguridad antes de la migración.',
    'no_recovery_warning' => 'Si pierdes la contraseña del bloqueo de la app y no tienes copia de seguridad ni otro dispositivo de confianza, tus datos no se podrán recuperar.',
    'recover_help' => 'Para recuperar el acceso, vuelve a vincular este dispositivo desde otro dispositivo de confianza o usa tu propia copia de seguridad cifrada.',
    'amounts_plaintext' => 'Los importes no se cifran en reposo: los saldos y los totales siguen siendo legibles para que tus totales mensuales sigan cuadrando.',
    'search_plaintext' => 'El índice de búsqueda guarda una copia en texto plano del nombre del comercio y de la descripción para que la búsqueda de texto completo siga funcionando.',
    'keep_unencrypted' => 'Mantener los datos sin cifrar',
    'encryption_enabled' => 'Cifrado activado',
    'encryption_enabled_body' => 'Tus datos ya están cifrados en reposo.',
    'done_encryption_enabled' => 'Hecho — cifrado activado',
    'encryption_failed' => 'No se ha podido configurar el cifrado',
    'encryption_failed_body' => 'Tus datos no se han modificado. Tu copia de seguridad se ha conservado.',
    'close_no_changes' => 'Cerrar — no se ha cambiado nada',

    'remove_this_device' => 'Eliminar este dispositivo',
    'removing' => 'Eliminando:',
    'remove_rotates_key' => 'Al eliminar este dispositivo se rota la clave de cifrado, de modo que no recibirá ninguna actualización futura.',
    'remove_cannot_erase' => 'No puede borrar los datos que ya estén en ese dispositivo. Si el dispositivo se ha perdido o te lo han robado, da por expuestos todos los datos que contuviera.',
    'remove_device' => 'Eliminar el dispositivo',
    'keep_device' => 'Mantener el dispositivo',
    'rotating_key' => 'Rotando la clave de cifrado…',

    'flash' => [
        'app_lock_first' => 'Define primero un bloqueo de la app para activar la sincronización.',
        'enable_failed' => 'No se ha podido activar la sincronización. Comprueba que el bloqueo de la app esté activo e inténtalo de nuevo.',
        'cannot_remove_self' => 'No puedes eliminar este dispositivo: es el que estás usando.',
        'remove_failed' => 'No se ha podido eliminar el dispositivo. Inténtalo de nuevo.',
        'app_lock_first_settings' => 'Define primero un bloqueo de la app para cambiar los ajustes de sincronización.',
        'relay_cleared' => 'Endpoint del relay borrado.',
        'relay_saved' => 'Endpoint del relay guardado.',
        'relay_save_failed' => 'No se ha podido guardar el endpoint del relay: :message',
    ],
];
