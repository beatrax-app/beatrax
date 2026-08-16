<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tu banco',
    'h1' => 'Descarga un extracto y suéltalo aquí abajo',
    'lede' => 'Elige el formato que te ha dado tu banco y suelta el archivo. Detectamos CAMT.053 y MT940 automáticamente.',

    'format_group_aria' => 'Formato del extracto bancario',
    'got_it_as' => 'Lo tengo como:',
    'badge_recommended' => 'recomendado',

    'mini' => [
        'login_label' => 'Inicia sesión',
        'login_sub' => 'En la web de tu banco',
        'statements_label' => 'Abre los extractos',
        'statements_sub' => 'En el menú de tu banco',
        'range_label' => 'Elige un periodo',
        'range_sub' => 'Últimos 90 días',
        'download_label' => 'Descarga',
    ],

    'csv_picker_aria' => '¿Qué banco ha exportado tu CSV?',
    'csv_picker_from' => 'De:',

    'drop_lead_camt053' => 'Suelta aquí tu archivo CAMT.053',
    'drop_lead_mt940' => 'Suelta aquí tu archivo MT940',
    'drop_lead_asn' => 'Suelta aquí tu CSV de ASN',
    'drop_lead_ing' => 'Suelta aquí tu CSV de ING',
    'drop_lead_pick_bank' => 'Elige qué banco ha exportado tu CSV — necesitamos saberlo para leerlo correctamente.',
    'drop_lead_default' => 'Suelta aquí tu archivo de extracto',
    'browse_file' => 'o busca un archivo',

    'banks_mt940' => 'Compatibles: ASN, ING, Rabobank, Triodos, SNS, Bunq',
    'banks_csv' => 'Compatibles: ASN, ING — llegarán más formatos a medida que los usuarios aporten muestras.',
    'banks_default' => 'Compatibles: ASN, ING',

    'file_ready' => '· ✓ listo',

    'skip' => 'Omitir este paso',
    'continue' => 'Continuar →',

    'errors' => [
        'file_required' => 'Suelta primero tu archivo de extracto en el recuadro.',
        'file_max' => 'Ese archivo es demasiado grande. Suelta un extracto de menos de 10 MB.',
        'file_extensions' => 'Ese archivo no parece un extracto bancario. Suelta un XML de CAMT.053, un CSV o un archivo MT940.',
        'pick_bank' => 'Elige qué banco ha exportado tu CSV antes de continuar.',
        'unreadable' => 'No se ha podido leer este archivo. El error completo está en /dev/logs.',
    ],
];
