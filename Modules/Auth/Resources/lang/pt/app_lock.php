<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'O desbloqueio biométrico não está disponível neste dispositivo.',
    'error_enroll_unprotected' => 'O desbloqueio biométrico precisa de um arquivo de chaves do sistema operativo, e esta instalação não tem nenhum. Registá-lo deixaria a chave de desbloqueio legível ao lado dos teus dados, por isso não é oferecido aqui.',
    'error_enroll_locked' => 'Desbloqueia a aplicação antes de fazeres o registo.',
    'error_enroll_failed' => 'O teu dispositivo recusou guardar a chave. O desbloqueio biométrico não está disponível.',
    'heading' => 'Bloqueio da aplicação',

    'moved_help' => 'O teu PIN, o tempo de bloqueio automático e o desbloqueio biométrico estão nas definições de sincronização deste dispositivo.',
    'moved_cta' => 'Abrir Sincronização e dispositivo',

    'toggle_label' => 'Bloquear a aplicação com PIN',
    'toggle_description' => 'Substitui o início de sessão diário por um PIN. As sessões mantêm-se ativas durante 30 dias.',

    'setup_heading' => 'Define um PIN para ativar o bloqueio',
    'new_pin_label' => 'Novo PIN (6–10 dígitos)',
    'confirm_pin_label' => 'Confirmar PIN',
    'account_password_label' => 'Palavra-passe da conta',
    'account_password_note' => '(necessária para criar uma chave de recuperação)',
    'account_password_placeholder' => 'A palavra-passe da tua conta',
    'set_pin' => 'Definir PIN',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Altera o teu PIN atual.',
    'change_pin' => 'Alterar PIN',
    'forgot_pin_link' => 'Esqueceste-te do PIN? Repõe-o com a palavra-passe da conta.',

    'biometric_enrolled_description' => 'Este dispositivo está registado para desbloqueio biométrico.',
    'biometric_enroll_description' => 'Regista este dispositivo para desbloquear com biometria.',
    'remove' => 'Remover',
    'enroll' => 'Registar',
    'biometric_unavailable' => 'O desbloqueio biométrico não está disponível neste dispositivo.',

    'deenroll_modal_heading' => 'Remover o desbloqueio biométrico — confirma com o PIN',
    'current_pin_label' => 'PIN atual',
    'remove_biometric' => 'Remover biometria',
    'keep_biometric' => 'Manter biometria',

    'auto_lock' => 'Bloqueio automático após',
    'idle_1' => '1 minuto',
    'idle_5' => '5 minutos',
    'idle_15' => '15 minutos',
    'idle_30' => '30 minutos',

    'disable_modal_heading' => 'Desativar o bloqueio da aplicação — confirma com o PIN',
    'disable_lock' => 'Desativar bloqueio',
    'keep_lock' => 'Manter bloqueio',

    'forgot_modal_heading' => 'Repor o PIN — confirma com a palavra-passe da conta',
    'forgot_modal_body' => 'A palavra-passe da conta recupera a chave de bloqueio, por isso repor o PIN nunca perde dados.',
    'confirm_new_pin_label' => 'Confirmar o novo PIN',
    'reset_pin' => 'Repor PIN',
    'cancel' => 'Cancelar',

    'change_modal_heading' => 'Alterar o PIN — confirma com o PIN atual',
    'keep_pin' => 'Manter PIN',

    'error_pin_too_short' => 'O PIN tem de ter pelo menos 6 dígitos.',
    'error_pin_digits' => 'O PIN tem de ter de 6 a 10 dígitos — apenas números.',
    'error_pin_mismatch' => 'Os PIN não coincidem. Tenta novamente.',
    'error_pin_required' => 'Introduz o teu PIN.',
    'error_pin_incorrect' => 'PIN incorreto.',
    'error_account_password_required' => 'Introduz a palavra-passe da tua conta.',
    'error_account_password' => 'Palavra-passe da conta incorreta.',
    'change_pin_success' => 'A tua chave de encriptação foi novamente protegida com o novo PIN.',
    'error_forgot_failed' => 'Não foi possível repor o PIN — a chave de recuperação não está disponível.',
    'error_enable_first' => 'Ativa primeiro o bloqueio por PIN antes de registares a biometria.',
    'error_disable_blocked_by_encryption' => 'As tuas notas e os dados das contrapartes estão cifrados com a chave que este bloqueio da aplicação guarda, por isso desligá-lo deixaria tudo ilegível. O bloqueio fica ativo — muda antes o teu PIN.',
    'error_key_material_lost' => 'Este dispositivo já não guarda a chave que abre os teus dados cifrados, por isso um PIN novo não os volta a tornar legíveis. Emparelha este dispositivo com um que ainda tenha a chave para os recuperares.',
    'error_recovery_wrap_stale' => 'A palavra-passe da conta já não abre este bloqueio da aplicação — foi alterada depois de o bloqueio estar configurado. O teu PIN continua a funcionar, mas não fica nada por trás dele se o esqueceres. Volta a ligar a palavra-passe da conta agora.',
    'relink_recovery' => 'Voltar a ligar a palavra-passe',
    'relink_modal_heading' => 'Voltar a ligar a palavra-passe — confirma com o PIN',
    'relink_recovery_success' => 'A palavra-passe da conta volta a poder recuperar este bloqueio da aplicação.',
];
