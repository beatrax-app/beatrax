<?php

declare(strict_types=1);

return [
    'page_title' => 'Repor a palavra-passe · Beatrax',
    'title' => 'Repor a palavra-passe',
    'subtitle' => 'Escreve o teu nome de utilizador, um dos teus códigos de recuperação e uma nova palavra-passe. O código fica marcado como usado.',
    'username' => 'Nome de utilizador',
    'recovery_code' => 'Código de recuperação',
    'recovery_code_hint' => '5 grupos de 4 caracteres, como A2BJ-XK9M-PQ7N-RX4F-V8HD.',
    'new_password' => 'Nova palavra-passe',
    'confirm_new_password' => 'Confirmar a nova palavra-passe',
    'submit' => 'Guardar a nova palavra-passe',
    'back' => 'Voltar ao início de sessão',

    'error_mismatch' => 'As palavras-passe não coincidem.',
    'error_generic' => 'Não foi possível repor a palavra-passe.',
    'success' => 'Palavra-passe atualizada. Inicia sessão com a nova palavra-passe.',

    'error_min_length' => 'Usa pelo menos 12 caracteres.',
    'error_wrong_code' => 'Esse nome de utilizador e esse código de recuperação não coincidem. Verifica o código com atenção — maiúsculas, sem zero, sem o, sem um, sem L.',
    'error_throttled' => 'Demasiadas tentativas — tenta novamente dentro de :wait.',
];
