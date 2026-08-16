<?php

declare(strict_types=1);

return [
    'page_title' => 'Alias',
    'heading' => 'Alias',
    'subtitle' => 'Nombres legibles que le has enseñado a Beatrax para las descripciones crípticas de tus extractos. Edita el patrón generalizado de una fila para ampliar o reducir qué otras transacciones heredan el mismo nombre legible.',
    'dismiss' => 'descartar',

    'selected_count' => ':count seleccionados',
    'merge_selected' => 'Fusionar seleccionados',

    'empty_heading' => 'Aún no hay alias',
    'empty_body' => 'Los alias aparecen aquí después de que hagas clic en la descripción original en cursiva de una fila de la vista previa de importación y le des un nombre legible.',

    'col_select' => 'Seleccionar',
    'col_raw' => 'Descripción original',
    'col_generalized' => 'Patrón generalizado',
    'col_friendly' => 'Nombre legible',
    'col_actions' => 'Acciones',

    'select_alias_aria' => 'Seleccionar el alias :name',
    'generalized_pattern_aria' => 'Patrón generalizado',

    'save' => 'Guardar',
    'cancel' => 'Cancelar',
    'edit' => 'Editar',
    'delete' => 'Eliminar',
    'delete_confirm' => '¿Eliminar este alias? Las próximas importaciones de «:pattern» volverán a usar la descripción original.',

    'backup_transfer' => 'Copia de seguridad y traspaso',
    'export_yaml' => 'Exportar los alias como YAML',

    'export_help_html' => 'Descarga <code class="font-mono">aliases.yaml</code> en el formato del corpus de la comunidad.',
    'import_from_yaml' => 'Importar desde YAML',
    'parse_preview' => 'Analizar y previsualizar',
    'cancel_import' => 'Cancelar la importación',

    'diff_new' => 'nuevos,',
    'diff_unchanged' => 'sin cambios,',
    'diff_conflicts' => 'conflictos.',

    'conflicts_heading' => 'Conflictos',
    'conflict_name' => 'nombre — actual: :existing → archivo: :file',
    'conflict_pattern_existing' => 'patrón — actual:',
    'conflict_file' => '→ archivo:',
    'resolution_for_aria' => 'Resolución para :pattern',
    'keep_yours' => 'Conservar el tuyo',
    'replace' => 'Sustituir',
    'confirm_import' => 'Confirmar la importación',

    'preview_aria' => 'Vista previa con las transacciones',
    'test_heading' => 'Probar con mis transacciones',
    'test_help' => 'Edita el patrón generalizado de una fila para ver con qué transacciones coincidiría.',
    'typing' => 'Escribiendo…',
    'matches_prefix' => 'Coincide con',
    'matches_suffix' => 'transacciones de tu historial reciente.',

    'merge_modal_title' => 'Fusionar :count alias',

    'merge_modal_help_html' => 'La fila que queda conserva su descripción original; las filas absorbidas se guardan en <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Nombre legible',
    'generalized_pattern_label' => 'Patrón generalizado',
    'no_prefix_warning' => 'No se ha encontrado ningún prefijo común de 4 caracteres entre los alias seleccionados — escribe un patrón a mano antes de confirmar.',
    'confirm_merge' => 'Confirmar la fusión',

    'flash' => [
        'updated' => 'Alias actualizado.',
        'deleted' => 'Alias eliminado.',
        'merged' => 'Alias fusionados.',
        'imported' => 'Se han importado :count alias.',
        'nothing' => 'No hay nada que importar.',
    ],

    'errors' => [
        'not_found' => 'Alias no encontrado (puede que se haya eliminado en otra pestaña).',
        'pattern_empty' => 'El patrón generalizado no puede estar vacío.',
        'select_two' => 'Selecciona al menos dos alias para fusionarlos.',
        'some_not_found' => 'No se han encontrado uno o varios de los alias seleccionados.',
        'both_required' => 'El nombre legible y el patrón generalizado son obligatorios.',
        'merge_not_found' => 'No se han encontrado uno o varios alias (puede que se hayan eliminado en otra pestaña).',
        'merge_failed' => 'La fusión ha fallado (:class).',
        'no_file' => 'No se ha subido ningún archivo.',
        'unreadable' => 'No se ha podido leer el archivo subido.',
        'too_short' => 'El patrón es demasiado corto para probarlo.',
    ],
];
