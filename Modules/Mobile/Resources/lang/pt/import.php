<?php

declare(strict_types=1);

return [
    'page_title' => 'Importar de outro dispositivo',

    'heading' => 'Importar de outro dispositivo',
    'subtitle' => 'Configura este telemóvel com a sua própria conta e bloqueio e depois emparelha-o com o teu outro dispositivo para trazer o teu histórico.',

    'username' => 'Nome de utilizador',
    'password' => 'Palavra-passe',
    'password_help' => 'Pelo menos 12 caracteres — não há reposição de palavra-passe, apenas códigos de recuperação.',
    'confirm_password' => 'Confirmar palavra-passe',
    'pin' => 'PIN de bloqueio da aplicação',
    'pin_help' => '6-10 dígitos — desbloqueia este dispositivo.',
    'confirm_pin' => 'Confirmar PIN',
    'continue' => 'Continuar',

    'failed_heading' => 'A configuração não terminou',
    'failed_body' => 'A tua conta foi criada, mas não foi possível concluir a configuração deste dispositivo. Podes tentar de novo sem problema.',
    'try_again' => 'Tentar novamente',

    'recovery_heading' => 'Guarda estes códigos de recuperação',
    'recovery_body' => 'Imprime-os ou guarda-os num sítio seguro. Não voltarão a ser mostrados.',
    'already_heading' => 'Este dispositivo já está configurado',
    'already_body' => 'A tua conta já existe neste dispositivo. Continua para o emparelhamento para o ligares aos teus outros dispositivos.',
    'recovery_download' => 'Transferir como .txt',
    'recovery_copy' => 'Copiar códigos',
    'recovery_copied' => 'Copiado',
    'recovery_copy_failed' => 'Não foi possível copiar. Anote os códigos.',
    'recovery_saved' => 'Guardado nas tuas transferências.',
    'recovery_confirm' => 'Guardei estes códigos num sítio seguro.',
    'continue_to_pairing' => 'Continuar para o emparelhamento',

    'errors' => [
        'passwords_mismatch' => 'As palavras-passe não coincidem.',
        'password_length' => 'Usa pelo menos 12 caracteres.',
        'pin_length' => 'O PIN tem de ter pelo menos 6 dígitos.',
        'pins_mismatch' => 'Os PIN não coincidem. Tenta novamente.',
        'session_expired' => 'A tua sessão expirou antes de a configuração terminar. Volta a introduzir o PIN e a palavra-passe.',
        'retry_failed' => 'Continua a não ser possível concluir a configuração deste dispositivo. Tenta novamente.',
        'account_failed' => 'Não foi possível criar a conta.',
    ],
];
