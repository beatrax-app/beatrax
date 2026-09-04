<?php

declare(strict_types=1);

return [
    'page_title' => 'Vista previa de la importación',

    'heading' => 'Vista previa de la importación',
    'subtitle' => 'Revisa lo que va a cambiar. No se guarda nada hasta que confirmes.',

    'stats' => [
        'category' => 'Categorías',
        'account' => 'Cuentas',
        'payee' => 'Contrapartes',
        'transaction' => 'Transacciones',
        'budget' => 'Meses de presupuesto',
    ],

    'all_clean' => 'Todo se ha asignado sin problemas: aquí no hay nada que decidir.',

    'nothing_staged' => 'Esta exportación no contenía nada que importar: aquí no hay nada que confirmar.',

    'discarded' => 'Descartaste esta importación, así que aquí ya no queda nada en la vista previa.',
    'discarded_link' => 'Iniciar una importación nueva',

    'groups' => [
        'conflict' => 'Necesita tu decisión',
        'extra' => 'No importado',
    ],

    'keep_or_take_aria' => 'Conservar lo local o tomar el origen para :label',
    'keep_local' => 'Conservar lo local',
    'take_source' => 'Tomar el origen',

    'footer_note' => 'Esto creará o actualizará las cantidades indicadas arriba en tus categorías, tus presupuestos y tu libro mayor.',
    'discard_button' => 'Descartar la importación',
    'discard_confirm' => '¿Descartar esta importación? Todo lo que se ha leído de tu archivo de exportación se elimina aquí, y recuperarlo significa volver a subir y procesar el archivo entero. A tu libro mayor todavía no ha llegado nada.',
    'confirm_button' => 'Confirmar la importación',
];
