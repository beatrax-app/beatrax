<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Esta versión de Beatrax no tiene dónde guardar una clave de desbloqueo, así que no ofrece desbloqueo biométrico. La limitación no es tu dispositivo.',
    'error_enroll_unprotected' => 'El desbloqueo biométrico necesita un almacén de claves del sistema operativo, y esta instalación no tiene ninguno. Registrarlo dejaría la clave de desbloqueo legible junto a tus datos, así que no se ofrece aquí.',
    'error_enroll_locked' => 'Desbloquea la app antes de registrar este dispositivo.',
    'error_enroll_failed' => 'Tu dispositivo rechazó guardar la clave. El desbloqueo biométrico no está disponible.',
    'heading' => 'Bloqueo de la app',

    'toggle_label' => 'Bloquear la app con PIN',
    'toggle_description' => 'Sustituye el inicio de sesión diario por un PIN. Las sesiones siguen activas durante 30 días.',

    'setup_heading' => 'Define un PIN para activar el bloqueo',
    'new_pin_label' => 'PIN nuevo (6–10 dígitos)',
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
    'biometric_unavailable' => 'Esta versión de Beatrax no puede ofrecer desbloqueo biométrico. Tu PIN es aquí el único desbloqueo.',

    'deenroll_modal_heading' => 'Quitar el desbloqueo biométrico — confirma con el PIN',
    'current_pin_label' => 'PIN actual',
    'remove_biometric' => 'Quitar biometría',
    'keep_biometric' => 'Mantener biometría',

    'auto_lock' => 'Bloqueo automático tras',
    'auto_lock_note' => 'Beatrax se bloquea tras ese tiempo sin actividad — y antes si lo dejas: cambiar a otra app, u ocultar o cerrar la ventana, bloquea Beatrax en menos de :window, diga lo que diga este ajuste.',
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

    'error_pin_too_short' => 'El PIN debe tener al menos 6 dígitos.',
    'error_pin_digits' => 'El PIN debe tener de :min a :max dígitos — solo números.',
    'error_pin_mismatch' => 'Los PIN no coinciden. Inténtalo de nuevo.',
    'error_pin_required' => 'Introduce tu PIN.',
    'error_pin_incorrect' => 'PIN incorrecto.',
    'error_account_password_required' => 'Introduce tu contraseña de la cuenta.',
    'error_account_password' => 'Contraseña de la cuenta incorrecta.',
    'change_pin_success' => 'Tu clave de cifrado se ha vuelto a proteger con tu PIN nuevo.',
    'error_forgot_failed' => 'No se pudo restablecer el PIN — la clave de recuperación no está disponible.',
    'error_enable_first' => 'Activa primero el bloqueo con PIN antes de registrar la biometría.',
    'error_disable_blocked_by_encryption' => 'Tus notas y datos de contrapartes están cifrados con la clave que guarda este bloqueo de la app, así que desactivarlo los dejaría ilegibles. El bloqueo se queda activado; cambia tu PIN en su lugar.',
    'error_key_material_lost' => 'Este dispositivo ya no guarda la clave que abre tus datos cifrados, así que un PIN nuevo no volverá a hacerlos legibles. Empareja este dispositivo con otro que todavía tenga la clave para recuperarlos.',
    'error_recovery_wrap_stale' => 'Tu contraseña de la cuenta ya no abre este bloqueo de la app — cambió después de configurar el bloqueo. Tu PIN sigue funcionando, pero no queda nada detrás si lo olvidas. Vuelve a vincular tu contraseña de la cuenta ahora.',
    'relink_recovery' => 'Volver a vincular la contraseña',
    'relink_modal_heading' => 'Volver a vincular la contraseña — confirma con el PIN',
    'relink_recovery_success' => 'Tu contraseña de la cuenta ya puede recuperar este bloqueo de la app otra vez.',
];
