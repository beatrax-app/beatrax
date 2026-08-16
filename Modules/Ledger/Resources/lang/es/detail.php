<?php

declare(strict_types=1);

return [
    'page_title' => 'Transacción',
    'heading' => 'Transacción',

    'counterparty' => 'Contraparte',
    'amount_native' => 'Importe (moneda original)',
    'amount_settled' => 'Importe (liquidado en EUR)',
    'effective_rate' => 'Tipo de cambio efectivo',
    'ics_markup' => 'Incluye el recargo de ICS, si lo hay.',

    'split' => [
        'category' => 'Categoría',
        'open' => 'Desglosar por categorías',
        'heading' => 'Desglosar entre varias categorías',
        'total' => 'Total :amount',
        'tax_per_category' => 'Las etiquetas fiscales se definen por categoría más abajo.',
        'choose_category' => 'Elige una categoría',
        'note_label' => 'Nota',
        'note_placeholder' => 'Nota (opcional)',
        'tax_deductible' => 'Deducible',
        'remove_leg_aria' => 'Quitar esta categoría',
        'add_category' => '+ Añadir categoría',
        'soft_cap' => ':count de ~20 categorías — plantéate agrupar los importes pequeños.',
        'remaining_zero' => 'Restante :amount ✓',
        'remaining_to_assign' => 'Pendiente de asignar: :amount',
        'over_allocated' => 'Asignado de más en :amount — reduce una línea.',
        'save' => 'Guardar el desglose',
        'saving' => 'Guardando…',
        'unsplit' => 'Deshacer el desglose',
        'remove_to_one' => 'Si quitas esta, solo queda una categoría — la transacción pasa a ser :category.',
        'remove_to_one_fallback' => 'esta categoría',
        'remove_category' => 'Quitar la categoría',
        'keep_category' => 'Mantener esta categoría',
        'restore_single' => '¿Restaurar como una sola categoría?',
        'confirm_unsplit' => 'Sí, deshacer el desglose',
        'keep_split' => 'Mantener el desglose',
    ],

    'tax' => [
        'section_aria' => 'Etiqueta fiscal',
        'label' => 'Deducible',
    ],

    'reclassify' => [
        'heading' => 'Reclasificar',
        'help' => 'Sustituye el tipo detectado. Si esta transacción está emparejada con otra, elegir un tipo que no sea transferencia desemparejará ambos lados.',
        'choose_aria' => 'Elegir un nuevo tipo de transacción',
        'choose_option' => 'Elige un tipo…',
        'save' => 'Guardar',
    ],

    'note' => [
        'heading' => 'Nota',
        'help' => 'Nota personal para esta transacción. Solo la ves tú.',
        'label' => 'Nota',
        'placeholder' => 'Añade una nota…',
        'save' => 'Guardar nota',
        'saved' => 'Guardada',
    ],

    'reassign' => [
        'heading' => 'Reasignar la contraparte',
        'help' => 'Sustituye la contraparte detectada para esta transacción.',
        'choose_aria' => 'Elegir contraparte',
        'choose_option' => 'Elige una contraparte…',
        'submit' => 'Reasignar',
    ],

    'delete' => [
        'heading' => 'Eliminar la transacción',
        'help' => 'Elimina esta transacción de forma permanente. Esta acción no se puede deshacer.',
        'button' => 'Eliminar',
        'confirm_prompt' => '¿Seguro?',
        'confirm' => 'Sí, eliminar',
        'cancel' => 'Cancelar',
    ],

    'chain' => [
        'view' => 'Ver la cadena',
    ],

    'toast' => [
        'reconciled_locked' => 'Esta transacción está conciliada. Anula la conciliación para poder modificarla.',
        'reclassified_pair_removed' => 'Reclasificada como :type — emparejamiento eliminado',
        'reclassified' => 'Reclasificada como :type',
        'note_saved' => 'Nota guardada',
        'unreconciled' => 'Conciliación anulada — ya puedes volver a editar esta transacción.',
        'counterparty_updated' => 'Contraparte actualizada',
        'split_saved' => 'Desglose guardado',
        'removed_one_remains' => 'Eliminada — queda una categoría',
        'unsplit_restored' => 'Desglose deshecho — restaurada a una sola categoría',
    ],

    'errors' => [
        'totals_must_match' => 'No se ha podido guardar — el total de las líneas debe coincidir exactamente con el total de la transacción.',
        'not_found' => 'No se ha encontrado la transacción.',
        'amount_zero' => 'El importe no puede ser 0,00 €',
        'choose_category' => 'Elige una categoría.',
        'choose_before_removing' => 'Elige una categoría antes de eliminar.',
        'choose_before_unsplitting' => 'Elige una categoría antes de deshacer el desglose.',
        'not_found_or_unowned' => 'Transacción no encontrada o que no pertenece al usuario.',
        'reconciled_split' => 'Esta transacción está conciliada. Anula la conciliación para cambiar su desglose.',
        'not_splittable' => 'El tipo de transacción «:type» no se puede desglosar.',
        'min_two_legs' => 'Un desglose requiere al menos 2 líneas.',
        'legs_non_zero' => 'Los importes de las líneas no pueden ser cero.',
        'legs_parent_sign' => 'Los importes de las líneas deben tener el mismo signo que la transacción principal.',
        'leg_category_not_accessible' => 'Categoría de línea no encontrada o no accesible para el usuario.',
        'survivor_not_accessible' => 'Categoría restante no encontrada o no accesible para el usuario.',
        'survivor_must_be_current' => 'La categoría restante debe ser una de las categorías de línea actuales del desglose.',
    ],
];
