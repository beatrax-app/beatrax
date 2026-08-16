<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Presupuestos',
        'subtitle' => 'Asigna cada euro — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Periodo anterior',
        'next_aria' => 'Periodo siguiente',
    ],

    'ready' => [
        'label' => 'Listo para asignar',
        'overassigned' => 'Has asignado más de lo que tienes — reduce un sobre o espera a tener más ingresos.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Aún no has asignado nada',
        'copy_hint' => 'Copia el plan del mes pasado o haz clic en una celda de abajo para empezar a asignar.',
        'first_hint' => 'Haz clic en una celda de abajo para empezar a asignar tu primer mes.',
        'copy_button' => 'Copiar el mes pasado',
    ],

    'no_categories' => [
        'heading' => 'Aún no hay categorías de gasto',
        'body' => 'Añade una categoría de gasto para empezar a asignarle dinero.',
    ],

    'table' => [
        'category' => 'Categoría',
        'assigned' => 'Asignado',
        'spent' => 'Gastado',
        'available' => 'Disponible',
        'if_overspent' => 'Si hay exceso de gasto',
        'notify_at' => 'Avisar al',
        'actions' => 'Acciones',
    ],

    'badge' => [
        'carries_negative' => 'Arrastra el negativo',
        'non_eur_aria' => 'El gasto en moneda distinta del EUR de esta categoría no se muestra aquí — consulta el panel',
        'non_eur_title' => 'El gasto no EUR no se muestra aquí — consulta el panel',
        'over_budget' => ':count por encima del presupuesto',
    ],

    'row' => [
        'assigned_aria' => 'Asignado para :category',
        'overspend_aria' => 'Si hay exceso de gasto en :category',
        'notify_aria' => 'Avisarme al porcentaje usado de :category',
        'move_money' => 'Mover dinero',
        'move' => 'Mover',
    ],

    'overspend' => [
        'reduce' => 'Reducir el «listo para asignar» del mes que viene',
        'carry' => 'Arrastrar el negativo en este sobre',
    ],

    'history' => [
        'show' => 'Mostrar historial ↓',
        'hide' => 'Ocultar historial ↑',
        'moved_from' => 'Movido desde :category',
        'moved_to' => 'Movido a :category',
        'undo' => 'Deshacer',
    ],

    'phone' => [
        'spent' => 'Gastado :amount',
        'available' => 'Disponible :amount',
        'notify_at' => 'Avisar al',
    ],

    'modal' => [
        'move_from' => 'Mover desde :name',
        'move_from_fallback' => 'sobre',
        'move_to' => 'Mover a',
        'no_other' => 'No hay otros sobres',
        'select' => 'Elige un sobre',
        'amount' => 'Importe',
        'available_in' => 'Disponible en :name: :amount',
        'note' => 'Nota (opcional)',
        'note_placeholder' => 'p. ej. Cubrir el exceso de restaurantes',
        'cancel' => 'Cancelar',
        'move_funds' => 'Mover fondos',
    ],

    'glance' => [
        'see_all' => 'Ver todo →',
    ],

    'notices' => [
        'invalid_amount' => 'Introduce un importe válido.',
        'threshold_range' => 'Introduce un número entero entre 1 y 200.',
        'copied_last_month' => 'Plan del mes pasado copiado.',
        'choose_envelope' => 'Elige un sobre al que mover el dinero.',
        'amount_positive' => 'Introduce un importe mayor que cero.',
        'move_failed' => 'No se pudo completar el movimiento — inténtalo de nuevo.',
        'money_moved' => 'Dinero movido.',
        'move_undone' => 'Movimiento deshecho.',
    ],

    'errors' => [
        'assigned_negative' => 'El importe asignado no puede ser negativo.',
        'invalid_overspend_mode' => 'Modo de exceso de gasto no válido.',
        'threshold_range' => 'El umbral de aviso debe estar entre 1 y 200.',
        'same_envelope' => 'El sobre de origen y el de destino deben ser distintos.',
        'non_positive_amount' => 'Importe no válido o no positivo.',
        'category_not_found' => 'Categoría no encontrada o no accesible para el usuario.',
    ],
];
