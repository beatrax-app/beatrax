<?php

declare(strict_types=1);

return [
    'page_title' => 'Reglas',
    'heading' => 'Reglas',
    'intro' => 'Precategoriza las transacciones al importarlas. Las reglas se aplican a todas las fuentes: banco, tarjeta, PayPal y recibos por correo.',
    'device_local_note' => 'Las reglas permanecen en este dispositivo. No se comparten con tus otros dispositivos.',

    'reapply' => 'Volver a aplicar las reglas al historial',
    'reapplying' => 'Aplicando de nuevo…',
    'new_rule' => 'Nueva regla',

    'reapply_progress_lead' => 'Volviendo a aplicar las reglas…',
    'reapply_progress_of' => 'de',
    'reapply_progress_trail' => 'transacciones revisadas',

    'empty_heading' => 'Aún no hay reglas',
    'empty_body' => 'Las reglas comparan las transacciones con varias condiciones y aplican cambios de categoría, contraparte, nota y etiqueta fiscal automáticamente: al importar y cada vez que las vuelves a aplicar a tu historial.',
    'empty_cta' => 'Crea tu primera regla',

    'col_priority' => 'Prioridad',
    'col_conditions' => 'Condiciones',
    'col_actions' => 'Acciones',
    'col_hits' => 'Coincidencias',
    'col_created' => 'Creada',
    'col_row_actions' => 'Acciones',
    'inactive_badge' => 'Inactiva',
    'inactive_title' => 'Esta regla no se ejecuta. Una regla se desactiva cuando se elimina la categoría o la contraparte a la que apunta.',

    'more_conditions' => '+:count más',

    'delete_confirm' => '¿Eliminar?',
    'delete_yes' => 'Sí, eliminar',
    'cancel' => 'Cancelar',
    'edit' => 'Editar',
    'delete' => 'Eliminar',
    'edit_aria' => 'Editar la regla (prioridad :priority)',
    'delete_aria' => 'Eliminar la regla (prioridad :priority)',

    'footer_note' => 'Las reglas y el historial de comercios funcionan juntos. Eliminar una regla no borra lo que Beatrax ha aprendido de categorizaciones anteriores: la próxima importación puede seguir sugiriendo la misma categoría a partir del historial.',

    'chip_category' => 'Categoría: :path',
    'chip_counterparty' => 'Contraparte: :path',
    'chip_note' => 'Nota',
    'chip_tax_tag' => 'Etiqueta fiscal',

    'flash_deleted' => 'Regla eliminada.',
    'flash_not_found' => 'No se ha encontrado la regla (puede que se haya eliminado en otra pestaña).',
    'flash_saved' => 'Regla guardada.',
    'flash_reapplying' => 'Volviendo a aplicar las reglas a tu historial…',
    'summary_no_changes' => 'Sin cambios — tu historial ya coincide con tus reglas.',
    'summary_updated' => 'Se han actualizado :fields en :transactions.',
    'summary_fields' => ':count campo|:count campos',
    'summary_transactions' => ':count transacción|:count transacciones',
    'summary_reconciled_skipped' => 'Se ha omitido :count transacción conciliada.|Se han omitido :count transacciones conciliadas.',
];
