<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Editor de escenarios — :name',
    'rename_aria' => 'Renombrar escenario',
    'save' => 'Guardar',
    'save_changes' => 'Guardar cambios',
    'cancel' => 'Cancelar',
    'rename' => 'Renombrar',
    'confirm_delete' => 'Confirmar eliminación',
    'delete_scenario' => 'Eliminar escenario',
    'delete_confirm' => '¿Eliminar este escenario?',

    'mutations_count' => 'Modificaciones (:count)',
    'no_mutations' => 'Aún no hay modificaciones. Añade una abajo para ver cómo se compara este escenario con tu referencia.',
    'editing' => 'Editando — :kind',
    'edit' => 'Editar',
    'remove' => 'Quitar',

    'add_mutation' => '+ Añadir modificación',
    'add_to_scenario' => 'Añadir al escenario',
    'pick_kind' => 'Elige un tipo de modificación:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Cancelar una serie',
            'desc' => 'Descarta todas las ocurrencias previstas de una serie aprobada.',
        ],
        'add_one_off' => [
            'title' => 'Añadir un cargo o ingreso puntual',
            'desc' => 'Un único evento hipotético en una fecha concreta.',
        ],
        'add_recurring' => [
            'title' => 'Añadir una serie recurrente',
            'desc' => 'Una nueva suscripción o fuente de ingresos hipotética.',
        ],
        'change_series_amount' => [
            'title' => 'Cambiar el importe de una serie',
            'desc' => 'Simula una subida o bajada de precio en una serie existente.',
        ],
        'shift_series_date' => [
            'title' => 'Desplazar la fecha de una serie',
            'desc' => 'Adelanta o retrasa la siguiente ocurrencia o todas las posteriores.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Serie que cancelar',
        'pick_series' => '— elige una serie —',
        'date' => 'Fecha',
        'amount' => 'Importe',
        'currency' => 'Moneda',
        'direction' => 'Sentido',
        'expense_long' => 'Gasto (dinero que sale)',
        'income_long' => 'Ingreso (dinero que entra)',
        'note' => 'Nota (opcional)',
        'start_date' => 'Fecha de inicio',
        'expense' => 'Gasto',
        'income' => 'Ingreso',
        'cadence' => 'Frecuencia',
        'cadence_weekly' => 'Semanal',
        'cadence_monthly' => 'Mensual',
        'cadence_quarterly' => 'Trimestral',
        'cadence_yearly' => 'Anual',
        'series' => 'Serie',
        'new_amount' => 'Importe nuevo',
        'new_next_date' => 'Nueva fecha siguiente',
        'scope' => 'Alcance',
        'scope_legend' => 'Qué ocurrencias desplazar',
        'scope_next' => 'Solo la siguiente ocurrencia',
        'scope_all' => 'Todas las ocurrencias posteriores',
    ],

    'whatif' => [
        'trigger' => 'Simular',
        'menu_aria' => 'Simular para :name',
        'model_cancellation' => 'Simular una cancelación',
        'model_amount_change' => 'Simular un cambio de importe…',
        'amount_dialog_aria' => 'Simular un cambio de importe para :name',
        'current_amount' => 'Importe actual',
        'new_amount' => 'Importe nuevo',
    ],

    'series_name_fallback' => 'serie',

    'summary' => [
        'cancel' => 'Cancelar :name',
        'series_fallback' => 'serie n.º :id',
        'one_off' => ':amount :currency el :date',
        'recurring' => ':amount :currency :cadence desde el :date',
        'change_amount' => ':name: importe nuevo :amount',
        'shift' => ':name: desplazar :scope al :date',
        'scope_all' => 'todas las siguientes',
        'scope_next' => 'la siguiente',
    ],

    'toast' => [
        'created' => 'Escenario «:name» creado.',
        'deleted' => 'Escenario eliminado.',
        'renamed' => 'Escenario renombrado.',
        'mutation_added' => 'Modificación añadida.',
        'mutation_updated' => 'Modificación actualizada.',
        'mutation_removed' => 'Modificación eliminada. Deshacer',
    ],

    'errors' => [
        'name_empty' => 'El nombre del escenario no puede estar vacío.',
        'name_too_long' => 'El nombre del escenario no puede superar los :max caracteres.',
        'name_taken' => 'Ya existe un escenario con ese nombre.',
        'pick_kind_first' => 'Elige primero un tipo de modificación.',
        'amount_positive' => 'El importe debe ser un número positivo.',
    ],
];
