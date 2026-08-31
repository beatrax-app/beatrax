<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tu cuenta de PayPal',
    'h1' => 'Conecta tu cuenta de PayPal',

    'lede_html' => 'Suelta la exportación de movimientos de PayPal — una fila por transacción, no el resumen de saldo. PayPal nombra sus informes en el idioma de tu cuenta, y de momento leemos el par neerlandés: <em lang="nl">Rapport Transactiegegevens</em>, no <span lang="nl">Saldorapport</span>. Si el tuyo sale en otro idioma, cambia PayPal a neerlandés antes de descargarlo.',

    'format_group_aria' => 'PayPal solo exporta en CSV',
    'got_it_as' => 'Lo tengo como:',
    'badge_only_format' => 'único formato',

    'mini' => [
        'login_label' => 'Iniciar sesión',
        'custom_label' => 'Extractos personalizados',
        'range_label' => 'Elige un periodo',
        'range_sub' => 'Últimos 12 meses',
        'download_label' => 'Descargar en CSV',
    ],

    'drop_lead' => 'Suelta aquí tu exportación de movimientos',
    'browse_file' => 'o busca un archivo',

    'file_ready' => '· ✓ listo',

    'skip' => 'Omitir este paso',
    'continue' => 'Continuar →',

    'errors' => [
        'required' => 'Suelta primero en el recuadro la exportación de movimientos de PayPal.',
        'max' => 'Ese archivo es demasiado grande. Una exportación de movimientos de PayPal suele ocupar bastante menos de 10 MB.',
        'extensions' => 'Ese archivo no parece un CSV de PayPal. Descarga la exportación de movimientos — una fila por transacción, no el resumen de saldo — en CSV.',
        'unreadable' => 'No se ha podido leer este archivo. El error completo está en /dev/logs.',
    ],
];
