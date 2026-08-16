<?php

declare(strict_types=1);

return [
    'page_title' => 'Gerir :name · Beatrax',
    'heading' => 'Gerir :name',
    'subtitle' => 'Consulta, repõe ou gera novos códigos para este utilizador.',

    'set_password' => [
        'heading' => 'Definir uma nova palavra-passe para este utilizador',
        'description' => 'No próximo início de sessão, ser-lhe-á pedido que escolha uma palavra-passe.',
        'open' => 'Definir uma nova palavra-passe para este utilizador',
        'body' => 'Define uma nova palavra-passe para :name. No próximo início de sessão, ser-lhe-á pedido que escolha uma palavra-passe.',
        'label' => 'Nova palavra-passe',
        'submit' => 'Definir palavra-passe',
        'cancel' => 'Cancelar',
    ],

    'regenerate' => [
        'heading' => 'Gerar novos códigos de recuperação para este utilizador',
        'description' => 'Os códigos antigos serão invalidados.',
        'open' => 'Gerar novos códigos de recuperação para este utilizador',
        'body' => 'Os códigos por usar que já existem deixam de funcionar. Vais ver os 10 novos códigos uma única vez e podes entregá-los.',
        'confirm_label' => 'Escreve o nome de utilizador para continuar',
        'submit' => 'Gerar novos códigos',
        'keep' => 'Manter os códigos atuais',
        'download' => 'Transferir como .txt',
    ],

    'error_min_length' => 'Usa pelo menos 12 caracteres.',
    'password_set' => 'Palavra-passe definida para :name. No próximo início de sessão, ser-lhe-á pedido que escolha uma palavra-passe.',
    'codes_regenerated' => 'Foram gerados dez novos códigos de recuperação para :name.',
];
