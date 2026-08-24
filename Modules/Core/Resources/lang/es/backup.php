<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => "Este teléfono no puede guardar un archivo que la aplicación le entrega, así que la copia cifrada se hace en la aplicación de escritorio. Vincula este dispositivo para mantener ambos sincronizados.",
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

        'intro_html' => 'Sustituye tu base de datos actual por una copia de seguridad cifrada. El archivo se descifra y se comprueba antes de cambiar nada, y primero se guarda una instantánea de tus datos actuales — pero aun así esto <strong class="text-slate-700 dark:text-slate-200">lo sobrescribe todo</strong>, así que está protegido.',
        'restored' => 'Restaurado. Recarga la app para ver tus datos restaurados.',
        'snapshot_saved_prefix' => 'Se ha guardado una instantánea de tus datos anteriores en',
        'file_label' => 'Copia de seguridad cifrada (.enc)',
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
        'choose_file' => 'Elige un archivo de copia de seguridad cifrada (.enc) para restaurar.',
        'enter_passphrase' => 'Introduce la frase de contraseña con la que se cifró la copia.',
        'unreadable' => 'No se ha podido leer el archivo subido. Inténtalo de nuevo.',
    ],
];
