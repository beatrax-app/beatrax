<?php

declare(strict_types=1);

return [
    'page_title' => 'Revisar cadenas',
    'heading' => 'Revisar cadenas',
    'hint' => ':count pista|:count pistas',
    'subtitle' => 'Confirma o rechaza los enlaces candidatos que el resolutor de cadenas no ha podido confirmar automáticamente.',

    'empty_heading' => 'No hay nada que revisar',
    'empty_body' => 'Todos los enlaces que el resolutor pudo emparejar están confirmados o rechazados. Los nuevos candidatos aparecerán aquí a medida que lleguen importaciones.',

    'auto_confirm_nudge' => 'Una confirmación más y los enlaces similares se confirmarán automáticamente.',

    'confirm' => 'Confirmar',
    'reject' => 'Rechazar',
    'confirm_aria' => 'Confirmar el enlace de cadena :id',
    'reject_aria' => 'Rechazar el enlace de cadena :id',
    'show_more' => 'Mostrar más',

    'kind' => [
        'paypal_funding' => 'Financiación con PayPal',
        'ics_bulk_settle' => 'Liquidación iDEAL agrupada',
    ],

    'errors' => [
        'confirm_hint' => 'Este candidato es una pista — ábrelo para adjuntar la transacción coincidente antes de confirmarlo.',
        'reject_hint' => 'Este candidato es una pista — ábrelo para adjuntar la transacción coincidente antes de rechazarlo.',
    ],
];
