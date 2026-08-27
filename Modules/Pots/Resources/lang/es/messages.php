<?php

declare(strict_types=1);

return [
    'page_title' => 'Huchas · Beatrax',
    'heading' => 'Huchas',
    'subtitle' => 'Subsaldos virtuales que siempre suman el saldo real de tu cuenta.',
    'add_pot' => 'Añadir hucha',

    'pot_fallback' => 'hucha',

    'empty' => [
        'heading' => 'Aún no hay huchas',
        'body' => 'Crea subsaldos virtuales dentro de cualquier cuenta para organizar tu dinero sin hacer una transferencia real.',
        'cta' => 'Añade tu primera hucha',
        'no_accounts_cta' => 'Importar un extracto',
    ],

    'common' => [
        'cancel' => 'Cancelar',
        'amount' => 'Importe',
        'note_optional' => 'Nota (opcional)',
    ],

    'actions' => [
        'fund' => 'Ingresar',
        'move' => 'Mover',
        'edit' => 'Editar',
        'withdraw' => 'Retirar',
        'archive' => 'Archivar',
        'restore' => 'Restaurar',
    ],

    'recon' => [
        'over_allocated' => 'Las huchas superan el saldo real en :amount — reequilibra para arreglarlo',
        'real_balance' => 'Saldo real:',
        'allocated' => 'Asignado:',
        'unallocated' => 'Sin asignar:',
    ],

    'chip' => [
        'goal' => 'Objetivo:',
        'goal_name_fallback' => 'Objetivo',
        'category_fallback' => 'Categoría',
    ],

    'coverage' => [
        'spent' => 'gastado',
        'in_pot' => 'en la hucha',
    ],

    'archive_confirm' => '¿Archivar esta hucha? El saldo de :amount volverá a lo no asignado.',
    'confirm_archive_aria' => 'Confirmar el archivado de :name',
    'more_actions_aria' => 'Más acciones para :name',

    'history' => [
        'show' => 'Mostrar historial ↓',
        'hide' => 'Ocultar historial ↑',
    ],

    'movement' => [
        'fund' => 'Ingreso',
        'withdraw' => 'Retirada',
        'moved_from' => 'Movido desde :name',
        'moved_to' => 'Movido a :name',
    ],

    'archived' => [
        'toggle' => 'Huchas archivadas (:count)',
        'badge' => 'Archivada',
    ],

    'form' => [
        'create_title' => 'Crear una hucha',
        'edit_title' => 'Editar hucha',
        'create_subtitle' => 'Ponle nombre a un subsaldo virtual dentro de una cuenta.',
        'edit_subtitle' => 'Actualiza el nombre o el vínculo de esta hucha.',
        'name' => 'Nombre',
        'name_placeholder' => 'p. ej. Fondo para las vacaciones',
        'account' => 'Cuenta',
        'select_account' => 'Selecciona una cuenta',
        'initial_amount' => 'Importe inicial (opcional)',
        'initial_amount_help' => 'El importe se descuenta de lo no asignado. Déjalo en blanco para crearla vacía.',
        'link_to' => 'Vincular a (opcional)',
        'link_goal' => 'Objetivo',
        'link_none' => 'Ninguno',
        'select_goal' => 'Selecciona un objetivo',
        'save_pot' => 'Guardar hucha',
        'save_changes' => 'Guardar cambios',
    ],

    'fund' => [
        'title' => 'Ingresar en la hucha',
        'heading' => 'Ingresar en :name',
        'submit' => 'Ingresar en la hucha',
        'note_placeholder' => 'p. ej. Ahorro mensual',
        'available' => 'Disponible para asignar: :amount (sin asignar)',
    ],

    'move' => [
        'title' => 'Mover fondos',
        'heading' => 'Mover desde :name',
        'to' => 'Mover a',
        'select_pot' => 'Selecciona una hucha',
        'no_others_short' => 'No hay más huchas',
        'no_others' => 'No hay más huchas en esta cuenta',
        'submit' => 'Mover fondos',
        'note_placeholder' => 'p. ej. Traspaso para las vacaciones',
    ],

    'withdraw' => [
        'heading' => 'Retirar de :name',
        'note_placeholder' => 'p. ej. Retirada',
    ],

    'available_in' => 'Disponible en :name: :amount',

    'errors' => [
        'enter_name' => 'Escribe un nombre para esta hucha.',
        'select_account' => 'Selecciona una cuenta para esta hucha.',
        'amount_exceeds_unallocated' => 'El importe supera el saldo sin asignar.',
        'amount_exceeds_unallocated_available' => 'El importe supera el saldo sin asignar (:amount disponible).',
        'amount_exceeds_pot_balance' => 'El importe supera el saldo de :name (:amount disponible).',
        'generic' => 'No se pudo guardar el bote. Revisa los campos e inténtalo de nuevo.',
        'amount_invalid' => 'Introduce un importe mayor que cero.',
        'goal_already_linked' => 'Este objetivo ya tiene un bote vinculado activo. Archívalo primero.',
    ],

    'toast' => [
        'pot_created' => 'Hucha creada.',
        'pot_updated' => 'Hucha actualizada.',
        'pot_funded' => 'Dinero ingresado en la hucha.',
        'withdrawn' => 'Retirado de la hucha.',
        'funds_moved' => 'Fondos movidos.',
        'pot_archived' => 'Hucha archivada.',
        'pot_restored' => 'Hucha restaurada.',
    ],
];
