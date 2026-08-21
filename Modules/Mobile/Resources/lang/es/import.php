<?php

declare(strict_types=1);

return [
    'page_title' => 'Importar desde otro dispositivo',

    'heading' => 'Importar desde otro dispositivo',
    'subtitle' => 'Configura este teléfono con su propia cuenta y su bloqueo, y luego vincúlalo con tu otro dispositivo para traerte tu historial.',

    'username' => 'Nombre de usuario',
    'password' => 'Contraseña',
    'password_help' => 'Al menos 12 caracteres — no hay restablecimiento de contraseña, solo códigos de recuperación.',
    'confirm_password' => 'Confirmar contraseña',

    'requirements_aria' => 'Requisitos de la contraseña',
    'req_length' => 'Al menos 12 caracteres',
    'req_match' => 'Las dos contraseñas coinciden',
    'req_met' => '(cumplido)',
    'req_unmet' => '(aún sin cumplir)',

    'pin' => 'PIN de bloqueo de la app',
    'pin_help' => 'De 6 a 10 dígitos — desbloquea este dispositivo.',
    'confirm_pin' => 'Confirmar PIN',
    'continue' => 'Continuar',

    'failed_heading' => 'La configuración no ha terminado',
    'failed_body' => 'Tu cuenta se ha creado, pero no hemos podido terminar de configurar este dispositivo. Puedes volver a intentarlo sin problema.',
    'try_again' => 'Intentar de nuevo',

    'recovery_heading' => 'Guarda estos códigos de recuperación',
    'recovery_body' => 'Imprímelos o guárdalos en un sitio seguro. No se volverán a mostrar.',
    'already_heading' => 'Este dispositivo ya está configurado',
    'already_body' => 'Tu cuenta ya existe en este dispositivo. Continúa con la vinculación para conectarlo con tus otros dispositivos.',
    'recovery_download' => 'Descargar como .txt',
    'recovery_copy' => 'Copiar códigos',
    'recovery_copied' => 'Copiado',
    'recovery_copy_failed' => 'No se ha podido copiar. Anote los códigos.',
    'recovery_saved' => 'Guardado en tus descargas.',
    'recovery_share_title' => 'Códigos de recuperación de Beatrax',
    'recovery_share_message' => 'Guárdelos en un lugar seguro.',
    'recovery_save_failed' => 'No se ha podido guardar el archivo. Anote los códigos.',
    'recovery_confirm' => 'He guardado estos códigos en un sitio seguro.',
    'continue_to_pairing' => 'Continuar con la vinculación',

    'errors' => [
        'username_required' => 'El nombre de usuario es obligatorio.',
        'passwords_mismatch' => 'Las contraseñas no coinciden.',
        'password_length' => 'Usa al menos 12 caracteres.',
        'pin_length' => 'El PIN debe tener al menos 6 dígitos.',
        'pins_mismatch' => 'Los PIN no coinciden. Inténtalo de nuevo.',
        'session_expired' => 'Tu sesión caducó antes de terminar la configuración. Vuelve a introducir tu PIN y tu contraseña.',
        'retry_failed' => 'Sigue sin poder terminarse la configuración de este dispositivo. Inténtalo de nuevo.',
        'account_failed' => 'No se pudo crear la cuenta.',
    ],
];
