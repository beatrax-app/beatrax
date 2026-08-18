<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Revisar y confirmar',
    'h1' => 'Revisa todo lo que hemos encontrado',

    'lede_across' => 'transacciones repartidas en',
    'source' => 'fuente|fuentes',
    'lede_confirm' => 'Comprueba tus saldos iniciales y confirma.',

    'empty' => 'Aún no hay nada que revisar. Suelta un extracto en los pasos anteriores para ver aquí tus transacciones.',

    'sb_eyebrow_label' => '🧮 SALDOS INICIALES ·',
    'account_detected' => 'CUENTA DETECTADA|CUENTAS DETECTADAS',
    'sb_lede' => 'Hemos detectado el saldo inicial de cada cuenta. Confírmalo o modifícalo antes de continuar.',

    'txn' => 'transacción|transacciones',
    'to_commit' => 'por confirmar ·',
    'already_imported' => 'ya importadas',
    'commit_committing' => 'Confirmando…',
    'commit_count' => 'Confirmarlo todo (:count transacciones) →',
    'commit_empty' => 'Confirmarlo todo (—) →',
    'skip' => 'Omitir por ahora',

    'errors' => [
        'nothing_to_commit' => 'No hay nada que confirmar.',
        'commit_failed' => 'No hemos podido confirmar tus extractos. No se ha cambiado nada — inténtalo de nuevo.',
    ],

    'section' => [
        'from_prefix' => 'DE ',
        'from_bank' => 'DE TU EXTRACTO BANCARIO',
        'from_ics' => 'DE TUS EXTRACTOS DE TARJETA ICS',
        'from_paypal' => 'DE PAYPAL',
        'row' => 'FILA|FILAS',
        'badge_ready' => '✓ LISTO',
        'badge_empty' => 'VACÍO',
        'badge_error' => 'VUELVE A SUBIRLO',
        'badge_filtered' => 'YA IMPORTADO',
        'error_body' => 'No hemos podido leer todos los archivos de esta fuente. Prueba con otro archivo →',
        'empty_body' => 'Este extracto está vacío.',
        'filtered_body' => 'Este extracto ya se importó en otro sitio — lo hemos dejado fuera.',
        'col_date' => 'Fecha',
        'col_type' => 'Tipo',
        'col_counterparty' => 'Contraparte',
        'col_amount' => 'Importe',
        'load_more' => 'Cargar más (:remaining restantes)',
        'rows_shown' => ':count filas mostradas',
    ],
];
