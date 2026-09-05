<?php

declare(strict_types=1);

return [
    'about_body' => 'Un archivo YAML incluido que relaciona códigos crípticos de los extractos bancarios con nombres de comercio legibles. Al activarlo, Beatrax lee la lista cuando importas; al enviar una sugerencia se abre GitHub en tu navegador.',

    'mappings' => ':count correspondencia|:count correspondencias',
    'contributors' => ':count colaborador|:count colaboradores',

    'use_shared_list' => [
        'title' => 'Usar la lista compartida de comercios',
        'help' => 'Deja que Beatrax lea la lista incluida para rellenar nombres legibles de los comercios que no hayas renombrado tú.',
    ],

    'offer_to_contribute' => [
        'title' => 'Ofrecerte a contribuir',
        'help' => 'Muestra el botón «Ayuda a otros a identificar esto» en la fila de triaje para que puedas enviar una sugerencia a la lista compartida con un solo clic.',
        // i18n-review: es · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Muestra el botón «Ayuda a otros a identificar esto» en la fila de triaje para que puedas enviar una sugerencia a la lista compartida con un solo toque.',
    ],

    'update_on_updates' => [
        'title' => 'Actualizar la lista compartida con las actualizaciones de la app',
        'help' => 'Actualiza la lista incluida cada vez que Beatrax se actualice.',
        'help_phone' => 'Actualiza la lista incluida cada vez que se instale una nueva versión de Beatrax desde la App Store o Google Play.',
        'note' => 'Se activará con una futura actualización de la app — la versión que estás usando aparece en la parte superior de la barra lateral.',
    ],
];
