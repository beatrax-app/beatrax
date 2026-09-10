<?php

declare(strict_types=1);

return [
    'page_title' => 'Desbloquear',

    'digits_entered' => ':count dígito introduzido|:count dígitos introduzidos',
    'pin_pad' => 'Teclado do PIN',
    'digit' => 'Dígito :digit',
    'backspace' => 'Retrocesso',
    'ok' => 'OK',
    'ok_aria' => 'OK — confirmar o PIN',
    'sign_out' => 'Terminar sessão',
    'forgot_pin' => 'Esqueceste-te do PIN? Termina sessão',

    'errors' => [
        'pin_length' => 'O PIN tem de ter pelo menos 6 dígitos.',

        'too_many_attempts' => 'Demasiadas tentativas — tenta novamente dentro de :secondss.',
        'incorrect_pin_remaining' => 'PIN incorreto. Resta :count tentativa.|PIN incorreto. Restam :count tentativas.',
        'incorrect_pin' => 'PIN incorreto.',

        'pin_changed' => 'O PIN deste dispositivo foi alterado durante o desbloqueio. Introduz o PIN atual.',

        'biometric_reset' => 'O desbloqueio biométrico foi reposto. Introduz o teu PIN e depois volta a ativá-lo em Bloqueio da aplicação.',
    ],
];
