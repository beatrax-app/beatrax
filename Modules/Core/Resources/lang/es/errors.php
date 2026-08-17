<?php

declare(strict_types=1);

return [
    'back' => 'Volver a Beatrax',

    '404' => [
        'title' => 'Esta página no existe',
        'body' => 'Puede que el enlace sea antiguo o que la página haya cambiado de nombre. Tus datos están bien.',
    ],

    '419' => [
        'title' => 'Tu sesión ha caducado',
        'body' => 'Estuviste fuera el tiempo suficiente para que la página caducara. Abre Beatrax otra vez y continúa.',
    ],

    '500' => [
        'title' => 'Algo ha salido mal',
        'body' => 'El problema se ha anotado en el registro de este dispositivo. Tus datos no se han modificado.',
    ],

    '503' => [
        'title' => 'Beatrax no está disponible un momento',
        'body' => 'Se está terminando una actualización o tarea de mantenimiento. Inténtalo en un momento.',
    ],
];
