<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Una hucha aparta dinero dentro de una cuenta en vez de sacarlo, así que el saldo en tu banco no cambia cuando ingresas o retiras. Cada cuenta muestra tres cifras: “:real” es lo que tiene el banco, “:allocated” es lo que tus huchas han reclamado entre todas, y “:unallocated” es lo que sobra: la única parte todavía libre para gastar. Cuando esa última cifra baja de cero, las huchas reclaman más de lo que hay en la cuenta, y cada saldo de hucha está inflado hasta que saques algo de vuelta.',
];
