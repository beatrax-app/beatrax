<?php

declare(strict_types=1);

return [
    'page_title' => 'Desbloquear',

    'digits_entered' => ':count dígito introducido|:count dígitos introducidos',
    'pin_pad' => 'Teclado del PIN',
    'digit' => 'Dígito :digit',
    'backspace' => 'Retroceso',
    'ok' => 'OK',
    'ok_aria' => 'OK — confirmar el PIN',
    'sign_out' => 'Cerrar sesión',
    'forgot_pin' => '¿Has olvidado el PIN? Cierra sesión: si la contraseña de tu cuenta todavía abre este bloqueo, puedes volver a entrar, establecer un PIN nuevo y no perder nada. Una contraseña restablecida con un código de recuperación, o que te haya puesto el titular de la cuenta, ya no lo abre.',

    'errors' => [
        'pin_length' => 'El PIN debe tener al menos 6 dígitos.',

        'too_many_attempts' => 'Demasiados intentos — inténtalo de nuevo en :secondss.',
        'incorrect_pin_remaining' => 'PIN incorrecto. Te queda :count intento.|PIN incorrecto. Te quedan :count intentos.',
        'incorrect_pin' => 'PIN incorrecto.',
    ],
];
