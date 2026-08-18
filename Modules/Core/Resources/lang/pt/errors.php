<?php

declare(strict_types=1);

return [
    'back' => 'Voltar ao Beatrax',

    '404' => [
        'title' => 'Esta página não existe',
        'body' => 'O link pode ser antigo, ou a página pode ter mudado de nome. Os teus dados estão bem.',
    ],

    '419' => [
        'title' => 'A tua sessão expirou',
        'body' => 'Estiveste ausente tempo suficiente para a página expirar. Abre o Beatrax outra vez e continua.',
    ],

    '500' => [
        'title' => 'Algo correu mal',
        'body' => 'O problema foi registado no registo deste dispositivo. Os teus dados não foram alterados.',
    ],

    '503' => [
        'title' => 'O Beatrax está indisponível por instantes',
        'body' => 'Está a terminar uma atualização ou manutenção. Tenta de novo daqui a pouco.',
    ],
];
