<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tu cuenta de PayPal',
    'h1' => 'Conecta tu cuenta de PayPal',

    'lede_html' => 'Suelta aquí tu exportación de PayPal con el detalle de las transacciones — aparece como <em lang="nl">Rapport Transactiegegevens</em> en una cuenta de PayPal neerlandesa. El informe de saldo (<span lang="nl">Saldorapport</span>) no sirve — necesitamos los datos evento a evento.',

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

    'drop_lead' => 'Suelta aquí tu CSV con el detalle de las transacciones',
    'browse_file' => 'o busca un archivo',

    'file_ready' => '· ✓ listo',

    'skip' => 'Omitir este paso',
    'continue' => 'Continuar →',

    'errors' => [
        'required' => 'Suelta primero en el recuadro tu CSV «Rapport Transactiegegevens» de PayPal.',
        'max' => 'Ese archivo es demasiado grande. Las exportaciones «Rapport Transactiegegevens» de PayPal suelen ocupar bastante menos de 10 MB.',
        'extensions' => 'Ese archivo no parece un CSV de PayPal. Descarga desde PayPal «Rapport Transactiegegevens» (no el informe de saldo «Saldorapport») en CSV.',
        'unreadable' => 'No se ha podido leer este archivo. El error completo está en /dev/logs.',
    ],
];
