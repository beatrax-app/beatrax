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
    'forgot_pin' => 'Esqueceste-te do PIN? Termina sessão — se a palavra-passe da conta ainda abrir este bloqueio, podes iniciar sessão de novo, definir um novo PIN e não perder nada. Uma palavra-passe reposta com um código de recuperação, ou definida pelo dono da conta, já não o abre.',

    'errors' => [
        'pin_length' => 'O PIN tem de ter pelo menos 6 dígitos.',

        'too_many_attempts' => 'Demasiadas tentativas — tenta novamente dentro de :secondss.',
        'incorrect_pin_remaining' => 'PIN incorreto. Resta :count tentativa.|PIN incorreto. Restam :count tentativas.',
        'incorrect_pin' => 'PIN incorreto.',
    ],
];
