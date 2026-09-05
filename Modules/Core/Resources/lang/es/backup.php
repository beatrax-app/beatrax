<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Esta aplicación no puede entregar un archivo a tu dispositivo, así que la copia cifrada se hace en la aplicación de escritorio. Vincula este dispositivo para mantener ambos sincronizados.',
        'unavailable' => 'Las copias de seguridad cifradas están disponibles en la versión de escritorio (SQLite). Con una base de datos en un servidor, usa las herramientas de copia de seguridad de la propia base de datos.',
        'intro' => 'Descarga una copia de toda tu base de datos cifrada con una frase de contraseña — puedes guardarla sin riesgo en un disco externo o en la nube, porque es ilegible sin la frase de contraseña (XChaCha20-Poly1305 + Argon2id, resistente a la computación cuántica).',
        'passphrase' => 'Frase de contraseña',
        'confirm_passphrase' => 'Confirmar la frase de contraseña',
        'keep_safe' => 'Guarda la frase de contraseña en un sitio seguro — sin ella no hay forma de recuperar la copia.',
        'submit' => 'Descargar la copia cifrada',
        'preparing' => 'Preparando…',
    ],

    'restore' => [
        'heading' => 'Restaurar desde una copia de seguridad',

        'intro_html' => 'Sustituye tu base de datos actual por una copia de seguridad cifrada. El archivo se descifra y se comprueba antes de cambiar nada, y primero se guarda una instantánea de tus datos actuales — pero aun así esto <strong class="text-slate-700 dark:text-slate-200">lo sobrescribe todo</strong>, así que está protegido. Se cerrará tu sesión, porque tu inicio de sesión también está en la base de datos.',
        'restored' => 'Tu copia de seguridad se restauró. Inicia sesión con el nombre de usuario y la contraseña vigentes cuando se creó.',
        'snapshot_saved_prefix' => 'Se ha guardado una instantánea de tus datos anteriores en',
        'file_label' => 'Archivo de copia de seguridad (.enc) o archivo de exportación (.zip)',
        'uploading' => 'Subiendo…',
        'passphrase' => 'Frase de contraseña',
        'confirm_prefix' => 'Escribe',
        'confirm_suffix' => 'para confirmar',
        'submit' => 'Restaurar (sobrescribe los datos actuales)',
        'restoring' => 'Restaurando…',
    ],

    'errors' => [
        'passphrase_min' => 'Usa una frase de contraseña de al menos :min carácter.|Usa una frase de contraseña de al menos :min caracteres.',
        'passphrase_mismatch' => 'Las dos frases de contraseña no coinciden.',
        'download_sqlite_only' => 'La descarga cifrada solo está disponible en la versión SQLite.',
        'create_failed' => 'No se pudo crear la copia de seguridad: :message',
        'confirm_phrase' => 'Escribe :phrase para confirmar — esto sustituye tus datos actuales.',
        'choose_file' => 'Elige desde dónde restaurar: el archivo .enc de la copia de seguridad o el .zip que escribió la exportación en un clic.',
        'upload_failed' => 'El archivo no terminó de subirse. Puede que sea demasiado grande para este dispositivo: restaurar en la aplicación de escritorio admite una copia más grande.',
        'enter_passphrase' => 'Introduce la frase de contraseña con la que se cifró la copia.',
        'unreadable' => 'No se ha podido leer el archivo subido. Inténtalo de nuevo.',
        'restore_wrong_passphrase' => 'Esa frase de contraseña no ha abierto esta copia de seguridad, y no se ha cambiado nada. Vuelve a escribirla e inténtalo otra vez. Si es la correcta, el archivo se ha alterado desde que se creó: restaura otra copia.',
        'restore_not_a_backup' => 'Este archivo no contiene ninguna copia de seguridad de Beatrax, así que no hay nada que restaurar y no se ha cambiado nada. Elige el archivo .enc que escribió la app al hacer la copia, o el .zip que escribió la exportación en un clic.',
        'restore_contents_unreadable' => 'La copia de seguridad se ha abierto, pero la base de datos que contiene está dañada, así que no se ha restaurado y no se ha cambiado nada. Restaura una copia anterior.',
        'restore_could_not_read' => 'No se ha podido leer el archivo de copia de seguridad, así que la restauración no se ha ejecutado y no se ha cambiado nada. Comprueba que este dispositivo tiene espacio libre e inténtalo otra vez.',
        'restore_not_supported' => 'La restauración funciona en la versión que guarda sus datos en un solo archivo, y esta no lo es, así que no se ha cambiado nada. En una base de datos de servidor, usa las herramientas de restauración de esa base de datos.',
        'restore_failed' => 'La restauración no se ha ejecutado y no se ha cambiado nada. Inténtalo otra vez; si sigue fallando, el registro de la app anota qué la detuvo.',
    ],
];
