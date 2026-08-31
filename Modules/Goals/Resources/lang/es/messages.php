<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Objetivos',
        'subtitle' => 'Sigue tu progreso hacia tus objetivos de ahorro.',
        'add_goal' => 'Añadir objetivo',
    ],

    'empty' => [
        'heading' => 'Aún no hay objetivos',
        'body' => 'Define un importe y una fecha objetivo para empezar a seguir tu progreso de ahorro.',
        'add_first' => 'Añade tu primer objetivo',
    ],

    'status' => [
        'overdue' => 'Vencido',
        'reached' => 'Alcanzado',
        'completed' => 'Completado',
        'archived' => 'Archivado',
    ],

    'row' => [
        'edit' => 'Editar',
    ],

    'progress' => [
        'aria' => ':name: :pct % completado',
    ],

    'card' => [
        'target_date' => 'Fecha objetivo: :date',
    ],

    'projection' => [
        'target_reached' => 'Objetivo alcanzado',
        'closed_short' => 'Cerrado antes del objetivo',
        'add_contributions' => 'Añade aportaciones para ver una previsión',
        'not_enough_history' => 'Aún no hay suficiente historial para prever una fecha',
        'no_recent_contributions' => 'No hay aportaciones recientes con las que prever una fecha',
        'too_far_to_date' => 'Demasiado lejos para poner fecha a este ritmo',
        'est' => 'Est. :date ·',
        'projection_note' => '(previsión)',
        'projected' => 'Previsto: :date',
    ],

    'archive' => [
        'confirm_question' => '¿Archivar este objetivo?',
        'close' => 'Cerrar',
        'confirm_aria' => 'Confirmar el archivado de :name',
        'archive' => 'Archivar',
    ],

    'actions' => [
        'more_aria' => 'Más acciones para :name',
        'mark_complete' => 'Marcar como completado',
        'mark_complete_caption' => 'Marcar',
        'archive' => 'Archivar',
        'restore' => 'Restaurar',
    ],

    'archived_disclosure' => 'Objetivo archivado (:count)|Objetivos archivados (:count)',

    'form' => [
        'title_edit' => 'Editar objetivo',
        'title_create' => 'Crear un objetivo de ahorro',
        'subtitle_edit' => 'Actualiza el nombre, el importe objetivo, la fecha o la hucha vinculada.',
        'subtitle_create' => 'Define un importe y una fecha objetivo para seguir tu progreso de ahorro.',
        'name' => 'Nombre',
        'name_placeholder' => 'p. ej. Fondo de emergencia',
        'target_amount' => 'Importe objetivo (:currency)',
        'target_date' => 'Fecha objetivo',
        'linked_pot' => 'Hucha vinculada (opcional)',
        'no_pot' => 'Sin hucha — usar el seguimiento de transferencias',
        'linked_pot_help' => 'Al vincularla, el saldo de la hucha determina el progreso de este objetivo.',
        'save_changes' => 'Guardar cambios',
        'save_goal' => 'Guardar objetivo',
        'close' => 'Cerrar',
    ],

    'summary' => [
        'see_all' => 'Ver todos →',
        'no_goals' => 'Aún no hay objetivos.',
        'add_first' => 'Añade tu primer objetivo →',
    ],

    'notices' => [
        'goal_created' => 'Objetivo creado.',
        'goal_updated' => 'Objetivo actualizado.',
        'goal_marked_complete' => 'Objetivo marcado como completado.',
        'goal_archived' => 'Objetivo archivado.',
        'goal_restored' => 'Objetivo restaurado.',
    ],

    'errors' => [
        'name' => 'Escribe un nombre para tu objetivo.',
        'date' => 'Elige una fecha objetivo.',
        'date_invalid' => 'Elige una fecha real.',
        'date_before_start' => 'Elige una fecha igual o posterior al inicio del objetivo.',
        'generic' => 'No se pudo guardar el objetivo. Revisa los campos e inténtalo de nuevo.',
        'amount' => 'Introduce un importe válido mayor que cero.',
        'pot_linked_category' => 'Esta hucha está vinculada a una categoría. Quita primero ese vínculo en la página Huchas.',
        'pot_already_linked' => 'Esta hucha ya financia otro objetivo. Quita primero ese vínculo allí.',
        'pot_missing' => 'Esa hucha ya no está disponible. Elige otra, o deja este objetivo sin vincular.',
    ],
];
