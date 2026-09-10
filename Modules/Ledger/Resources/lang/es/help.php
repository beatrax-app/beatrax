<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Conciliar es comparar Beatrax con la cifra del propio banco. El saldo conciliado es el saldo inicial de esta cuenta más cada línea que hayas marcado como conciliada hasta la fecha del extracto, y la diferencia es la cifra de tu extracto menos ese saldo. Marca y desmarca líneas en la lista de movimientos hasta que la diferencia llegue a cero: esta pantalla nunca inventa un apunte de ajuste. “:complete” bloquea después las líneas que cubre: una línea bloqueada no se puede editar, dividir ni borrar hasta que la desbloquees desde su propia página.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'En qué punto está una fila respecto a tu extracto bancario. “:uncleared” significa que Beatrax tiene el movimiento y tú aún no lo has casado con un extracto; toca la píldora para pasarlo a “:cleared”, y son esas marcas las que suma la pantalla de conciliación. “:reconciled” no se puede tocar: lo pone una conciliación completada, y esa bloquea la fila, así que categoría, nota, desglose y etiquetas fiscales se quedan como están hasta que la desbloquees.',
];
