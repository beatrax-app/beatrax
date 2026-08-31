<?php

declare(strict_types=1);

return [
    'title' => 'Revisar recurrentes',
    'subtitle' => 'Aprueba, pospón o rechaza las sugerencias de recurrentes detectadas.',

    'tabs' => [
        'pending' => 'Pendientes',
        'rejected' => 'Rechazadas',
        'cadence_changed' => 'Frecuencia cambiada',
    ],

    'bulk' => [
        'aria' => 'Acciones en lote',
        'selected' => ':count seleccionadas',
        'approve' => 'Aprobar :count',
        'reject' => 'Rechazar :count',
    ],

    'empty' => [
        'heading' => 'No hay nada que revisar',
        'pending' => 'Las sugerencias de recurrentes llegan aquí cuando el detector encuentra grupos mensuales estables.',
        'rejected' => 'Las sugerencias rechazadas aparecen aquí para que puedas recuperarlas si cambias de opinión.',
        'cadence_changed' => 'Las series aprobadas cuya frecuencia ha cambiado vuelven aquí para revisarlas de nuevo.',
    ],

    'next' => 'Siguiente',
    'overdue' => 'Vencido',
    'cadence_changed_note' => 'frecuencia cambiada',
    'un_reject' => 'Anular el rechazo',
    'approve' => 'Aprobar',
    'approve_aria' => 'Aprobar la serie recurrente :id',
    'reject' => 'Rechazar',
    'reject_aria' => 'Rechazar la serie recurrente :id',
    'snooze' => 'Posponer',
    'snooze_aria' => 'Posponer la serie recurrente :id',
    'snooze_1w' => '1 semana',
    'snooze_1m' => '1 mes',
    'snooze_3m' => '3 meses',
    'edit_name' => 'Editar el nombre',
    'edit_name_aria' => 'Cambiar el nombre de la serie recurrente :id',
    'new_name_label' => 'Nombre nuevo para esta serie',
    'load_more' => 'Cargar más',
    'save' => 'Guardar',

    'toast' => [
        'approved' => 'Aprobada',
        'rejected' => 'Rechazada',
        'snoozed' => 'Pospuesta',
        'renamed' => 'Renombrada',
        'un_rejected' => 'Rechazo anulado',
        'bulk_approved' => ':count aprobadas',
        'bulk_rejected' => ':count rechazadas',
    ],
];
