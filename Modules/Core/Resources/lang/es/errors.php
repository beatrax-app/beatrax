<?php

declare(strict_types=1);

return [
    'back' => 'Volver a Beatrax',

    'not_saved' => 'No se guardó nada. Tus datos no han cambiado: inténtalo de nuevo.',

    'no_longer_here' => 'Eso ya no existe.',

    '404' => [
        'title' => 'Esta página no existe',
        'body' => 'Puede que el enlace sea antiguo o que la página haya cambiado de nombre. Tus datos están bien.',
    ],
    '4xx' => [
        'title' => 'Esta solicitud no se puede procesar',
        'body' => 'La página se abrió de una forma que no espera. Tus datos no han cambiado.',
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
