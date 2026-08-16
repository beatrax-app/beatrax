<?php

declare(strict_types=1);

return [
    'page_title' => 'Gestionar :name · Beatrax',
    'heading' => 'Gestionar :name',
    'subtitle' => 'Consulta, restablece o regenera los códigos de este usuario.',

    'set_password' => [
        'heading' => 'Definir una contraseña nueva para este usuario',
        'description' => 'La próxima vez que inicie sesión se le pedirá que elija una contraseña.',
        'open' => 'Definir una contraseña nueva para este usuario',
        'body' => 'Define una contraseña nueva para :name. La próxima vez que inicie sesión se le pedirá que elija una contraseña.',
        'label' => 'Contraseña nueva',
        'submit' => 'Definir contraseña',
        'cancel' => 'Cancelar',
    ],

    'regenerate' => [
        'heading' => 'Regenerar los códigos de recuperación de este usuario',
        'description' => 'Los códigos antiguos quedarán anulados.',
        'open' => 'Regenerar los códigos de recuperación de este usuario',
        'body' => 'Sus códigos sin usar dejarán de funcionar. Verás los 10 códigos nuevos una sola vez y podrás entregárselos.',
        'confirm_label' => 'Escribe el nombre de usuario para continuar',
        'submit' => 'Regenerar códigos',
        'keep' => 'Mantener los códigos actuales',
        'download' => 'Descargar como .txt',
    ],

    'error_min_length' => 'Usa al menos 12 caracteres.',
    'password_set' => 'Contraseña definida para :name. La próxima vez que inicie sesión se le pedirá que elija una contraseña.',
    'codes_regenerated' => 'Se han generado diez códigos de recuperación nuevos para :name.',
];
