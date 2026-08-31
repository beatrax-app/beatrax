<?php

declare(strict_types=1);

return [
    'page_title' => 'Conciliar',
    'heading' => 'Conciliar',
    'intro' => 'Confirma el saldo del extracto de una cuenta frente a tus transacciones compensadas. Cuando coincidan, completa la conciliación para bloquear esas filas.',

    'account' => 'Cuenta',
    'choose_account' => 'Elige una cuenta…',
    'statement_date' => 'Fecha del extracto',
    'statement_balance' => 'Saldo del extracto (:symbol)',
    'balance_help' => 'Se rellena con tu último extracto importado cuando está disponible — en negativo si debes dinero, y editable en cualquier caso.',

    'cleared_balance' => 'Saldo compensado',
    'statement_target' => 'Objetivo del extracto',
    'difference' => 'Diferencia',

    'pill' => [
        'choose_account' => 'elige una cuenta',
        'choose_date' => 'elige la fecha del extracto',
        'enter_balance' => 'introduce el saldo del extracto',
        'matched' => 'coincide — :amount',
        'discrepancy' => 'discrepancia — :amount',
        'reconciled_through' => 'conciliada hasta :date',
    ],

    'mismatch_html' => 'El saldo del extracto aún no coincide con tu saldo compensado. Marca o desmarca filas como compensadas en la <a href=":url" class="underline">lista de transacciones</a> o ajusta el saldo introducido hasta que la diferencia llegue a cero — este flujo nunca crea un apunte de ajuste.',
    'unreachable_no_baseline_html' => 'Ninguna combinación de filas puede llevar esta diferencia a cero. Esta cuenta no tiene saldo de apertura registrado, así que su saldo se mide desde cero. Importa el extracto con el que abre la cuenta, o define el saldo de apertura en <a href=":url" class="underline">Ajustes</a>.',
    'unreachable' => 'Ninguna combinación de filas puede llevar esta diferencia a cero: queda fuera del rango de todas las filas de esta cuenta hasta la fecha indicada. Revisa la fecha del extracto y el saldo introducido.',

    'check' => 'Comprobar',
    'complete' => 'Completar la conciliación',
    'complete_unavailable' => 'Ya no queda nada que bloquear hasta esta fecha — marca más filas como compensadas o elige una fecha del extracto posterior.',

    'errors' => [
        'choose_account' => 'Elige primero una cuenta.',
        'invalid_balance_date' => 'Introduce un saldo y una fecha de extracto válidos.',
        'mismatch' => 'El saldo del extracto aún no coincide con el saldo compensado — ajusta las filas compensadas o el saldo introducido hasta que la diferencia sea cero.',
    ],

    'toast' => [
        'nothing_to_lock' => 'No hay nada que bloquear para esta fecha de extracto.',
        'complete' => 'Conciliación completada — :count fila bloqueada.|Conciliación completada — :count filas bloqueadas.',
    ],
];
