<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Objetivo: :name',
        'category_goal' => 'Objetivo de la categoría :name',
        'schedule_untitled' => 'Transacción programada sin nombre',
        'transaction' => 'Transacción: :name · :date · :amount',
        'transaction_unnamed' => 'Transacción',
        'amount_update' => 'Actualización del importe de la transacción',
        'budget_history' => 'Historial de presupuesto en :currency',
        'budget_file_currency' => 'Moneda del archivo de presupuesto',
        'budget_file_mode' => 'Modo del archivo de presupuesto',
    ],

    'conflict' => [
        'budget_assignment' => 'Asignación de presupuesto',
        'budget_for_month' => 'Presupuesto de :category · :month',
        'budget_for_category' => 'Presupuesto de :category',
        'category_name' => 'Nombre de la categoría',
        'category_name_of' => 'Nombre de la categoría «:name»',
        'account_name' => 'Nombre de la cuenta',
        'account_name_of' => 'Nombre de la cuenta «:name»',
        'transaction_amount' => 'Importe de la transacción',
        'transaction_amount_of' => 'Importe: :name',
        'transaction_amount_of_dated' => 'Importe: :name · :date',
        'transaction_description' => 'Descripción de la transacción',
        'transaction_description_of' => 'Descripción: :name',
        'transaction_description_of_dated' => 'Descripción: :name · :date',
        'other' => 'Valor importado',
    ],

    'reason' => [
        'fingerprint_collision' => 'Esta transacción chocó con otra transacción ya registrada (huella idéntica) y no se importó.',
        'split_legs_without_category' => ':count línea del desglose de :legs no tiene categoría, y una línea no se puede guardar sin ella. La transacción se importó por su importe completo y está esperando en la categoría :uncategorized.|:count líneas del desglose de :legs no tienen categoría, y una línea no se puede guardar sin ella. La transacción se importó por su importe completo y está esperando en la categoría :uncategorized.',
        'split_sum_mismatch' => 'Las líneas del desglose suman :legs pero la transacción es :total, y un desglose tiene que coincidir exactamente con su transacción. La transacción se importó por su importe completo, sin sus líneas.',
        'split_unstorable' => 'Beatrax no puede guardar este desglose tal como está, así que la transacción se importó sola, sin sus líneas.',
        'goal_without_target_date' => 'Este objetivo no tiene fecha objetivo; Beatrax necesita una para crear un objetivo de ahorro.',
        'goal_without_name' => 'Este objetivo no tiene nombre; Beatrax necesita uno para crear un objetivo de ahorro.',
        'goal_def_unsupported' => 'categories.goal_def usa una forma de plantilla no admitida (no plana) — el objetivo no se importó.',
        'budget_currency_mismatch' => ':count fila de presupuesto no se importó: tus presupuestos se llevan en :envelope, y esta exportación presupuesta en :source.|:count filas de presupuesto no se importaron: tus presupuestos se llevan en :envelope, y esta exportación presupuesta en :source.',
        'amount_apply_collision' => 'El nuevo importe del origen no se ha podido aplicar — choca con la huella de otra transacción (misma cuenta, fecha, moneda y contraparte). Se ha dejado sin cambios.',
        'amount_currency_mismatch' => 'Los importes de las transacciones no se conciliaron: estas transacciones se llevan en :local, y esta exportación las indica en :source. Se dejaron sin cambios.',
        'schedule_unsupported' => 'Las transacciones programadas y recurrentes todavía no tienen en Beatrax una vía para crearse desde un origen externo — se conservan solo como nota, no como una serie recurrente activa.',
        'saved_report_unsupported' => 'Los informes guardados y las configuraciones de análisis no tienen equivalente en Beatrax.',
        'assumed_currency' => "Se ha supuesto :currency — no se ha encontrado ninguna fila 'preferences.currencyCode' en esta exportación.",
        'assumed_budget_type' => "Se ha supuesto :mode — no se ha encontrado ninguna fila 'preferences.budgetType' en esta exportación.",
        'changed_on_both_sides' => "Tanto el archivo de origen como Beatrax han cambiado esto desde la última importación.\nLocal: :local\nOrigen: :source\nÚltima importación: :baseline",
        'take_source' => 'El valor de la nueva exportación se aplicará cuando confirmes — tu valor local será sustituido.',
        'keep_local' => 'Tu valor local se conservará — el valor de la nueva exportación no se aplicará.',
        'compared_values' => ":intro\nLocal: :local · Origen: :source · Última importación: :baseline",
    ],

    'value' => [
        'none' => '(ninguno)',
        'quoted' => '«:value»',
    ],
];
