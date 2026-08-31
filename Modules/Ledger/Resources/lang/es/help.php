<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Conciliar es comparar Beatrax con la cifra del propio banco. El saldo conciliado es el saldo inicial de esta cuenta más cada línea que hayas marcado como conciliada hasta la fecha del extracto, y la diferencia es la cifra de tu extracto menos ese saldo. Marca y desmarca líneas en la lista de movimientos hasta que la diferencia llegue a cero: esta pantalla nunca inventa un apunte de ajuste. “:complete” bloquea después las líneas que cubre: una línea bloqueada no se puede editar, dividir ni borrar hasta que la desbloquees desde su propia página.',
];
