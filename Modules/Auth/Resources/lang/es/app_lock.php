<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'El desbloqueo biométrico no está disponible en este dispositivo.',
    'error_enroll_locked' => 'Desbloquea la app antes de registrar este dispositivo.',
    'error_enroll_failed' => 'Tu dispositivo rechazó guardar la clave. El desbloqueo biométrico no está disponible.',
    'heading' => 'Bloqueo de la app',

    'moved_help' => 'Tu PIN, el tiempo de bloqueo automático y el desbloqueo biométrico están en los ajustes de sincronización de este dispositivo.',
    'moved_cta' => 'Abrir Sincronización y dispositivo',

    'toggle_label' => 'Bloquear la app con PIN',
    'toggle_description' => 'Sustituye el inicio de sesión diario por un PIN. Las sesiones siguen activas durante 30 días.',

    'setup_heading' => 'Define un PIN para activar el bloqueo',
    'new_pin_label' => 'PIN nuevo (4–10 dígitos)',
    'confirm_pin_label' => 'Confirmar PIN',
    'account_password_label' => 'Contraseña de la cuenta',
    'account_password_note' => '(necesaria para crear una clave de recuperación)',
    'account_password_placeholder' => 'Tu contraseña de la cuenta',
    'set_pin' => 'Definir PIN',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Cambia tu PIN actual.',
    'change_pin' => 'Cambiar PIN',
    'forgot_pin_link' => '¿Has olvidado el PIN? Restablécelo con la contraseña de tu cuenta.',

    'biometric_enrolled_description' => 'Este dispositivo está registrado para el desbloqueo biométrico.',
    'biometric_enroll_description' => 'Registra este dispositivo para desbloquearlo con biometría.',
    'remove' => 'Quitar',
    'enroll' => 'Registrar',
    'biometric_unavailable' => 'El desbloqueo biométrico no está disponible en este dispositivo.',

    'deenroll_modal_heading' => 'Quitar el desbloqueo biométrico — confirma con el PIN',
    'current_pin_label' => 'PIN actual',
    'remove_biometric' => 'Quitar biometría',
    'keep_biometric' => 'Mantener biometría',

    'auto_lock' => 'Bloqueo automático tras',
    'idle_1' => '1 minuto',
    'idle_5' => '5 minutos',
    'idle_15' => '15 minutos',
    'idle_30' => '30 minutos',

    'disable_modal_heading' => 'Desactivar el bloqueo de la app — confirma con el PIN',
    'disable_lock' => 'Desactivar bloqueo',
    'keep_lock' => 'Mantener el bloqueo',

    'forgot_modal_heading' => 'Restablecer el PIN — confirma con la contraseña de la cuenta',
    'forgot_modal_body' => 'La contraseña de tu cuenta recupera la clave de bloqueo, así que al restablecer el PIN no pierdes datos.',
    'confirm_new_pin_label' => 'Confirmar el PIN nuevo',
    'reset_pin' => 'Restablecer PIN',
    'cancel' => 'Cancelar',

    'change_modal_heading' => 'Cambiar el PIN — confirma con el PIN actual',
    'keep_pin' => 'Mantener PIN',

    'error_pin_too_short' => 'El PIN debe tener al menos 4 dígitos.',
    'error_pin_mismatch' => 'Los PIN no coinciden. Inténtalo de nuevo.',
    'error_pin_incorrect' => 'PIN incorrecto.',
    'error_account_password' => 'Contraseña de la cuenta incorrecta.',
    'change_pin_success' => 'Tu clave de cifrado se ha vuelto a proteger con tu PIN nuevo.',
    'error_forgot_failed' => 'No se pudo restablecer el PIN — la clave de recuperación no está disponible.',
    'error_enable_first' => 'Activa primero el bloqueo con PIN antes de registrar la biometría.',
];
