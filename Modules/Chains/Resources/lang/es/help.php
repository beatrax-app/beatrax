<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Un solo pago suele pagar varios otros: la liquidación de la tarjeta en la cuenta bancaria cubre un mes de compras con tarjeta, y una retirada del banco financia un pago con monedero hecho días antes. Una cadena registra qué cargo pagó qué, de modo que una compra de un extracto se puede seguir hasta el dinero que salió de verdad de tu cuenta. Beatrax enlaza por su cuenta los casos seguros y deja el resto en la cola de revisión. Confirma unas cuantas veces el mismo tipo de enlace y dejará de preguntarte por él.',
];
