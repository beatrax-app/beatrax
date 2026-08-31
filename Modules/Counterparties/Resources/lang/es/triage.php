<?php

declare(strict_types=1);

return [
    'page_title' => 'Triaje de contrapartes',
    'heading' => 'Clasifica las contrapartes desconocidas',

    'progress' => ':seen de :total · :percent % · quedan ~:minutes min',
    'progress_aria' => 'Progreso del triaje',

    'all_caught_aria' => 'Todas las contrapartes etiquetadas',
    'all_caught_heading' => '🎉 Todo al día — todas las contrapartes están etiquetadas.',
    'back_to_index' => 'Volver a las contrapartes →',

    'meta' => ':count transacción · visto por última vez el :date|:count transacciones · visto por última vez el :date',

    'suggested_aria' => 'Coincidencia sugerida',
    'suggestion_medium' => '✨ Quizá **:name** — confianza media',
    'suggestion_low' => 'Coincidencia de patrón: **:name** — confianza baja. Compruébalo antes de enlazar.',
    'suggestion_high' => '✨ Parece **:name** — confianza alta',

    'reasoning' => ':hits de :total transacción reciente en este IBAN apunta a :name.|:hits de :total transacciones recientes en este IBAN apuntan a :name.',
    'yes_link' => 'Sí, enlazar con :name ↵',
    'no_not' => 'No, no es :name',

    'recent_on_iban' => 'Transacciones recientes en este IBAN',
    'recent_on_counterparty' => 'Transacciones recientes con esta contraparte',
    'no_transactions_yet' => 'Aún no hay transacciones registradas.',

    'label_manually' => 'O etiquétala a mano',
    'label_question' => '¿Qué es esta contraparte?',
    'display_name_label' => 'Nombre visible',
    'type_label' => 'Tipo',
    'type_merchant' => 'Comercio',
    'type_personal' => 'Personal',
    'type_bank' => 'Banco',
    'type_government' => 'Administración',
    'save_label' => 'Guardar etiqueta',
    'name_required' => 'Primero dale un nombre a esta contraparte.',
    'draft_kept' => 'Lo que escribes se conserva mientras avanzas por la cola.',

    'skip' => 'Omitir por ahora',
    'mark_ignored' => 'No volver a preguntar por esta',
    'not_now_note' => 'Ninguna de las dos cambia la contraparte: aún puedes etiquetarla más tarde desde la página Contrapartes.',
    'previous' => 'Desconocida anterior',

    'kbd_yes' => 'sí',
    'kbd_no' => 'no',
    'kbd_skip' => 'omitir',
    'kbd_next' => 'siguiente',

    'footer' => ':seen ya etiquetadas · quedan :count',
];
