<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Un solo pago suele pagar varios otros: la liquidación de la tarjeta en la cuenta bancaria cubre un mes de compras con tarjeta, y una retirada del banco financia un pago con monedero hecho días antes. Una cadena registra qué cargo pagó qué, de modo que una compra de un extracto se puede seguir hasta el dinero que salió de verdad de tu cuenta. Beatrax enlaza por su cuenta los casos seguros y deja el resto en la cola de revisión. Confirma unas cuantas veces el mismo tipo de enlace y dejará de preguntarte por él.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Una pista es medio enlace: Beatrax encontró un lado de una cadena de pagos y no el otro, así que anotó lo que vio en vez de adivinar el resto. La mayoría se resuelven solas — una liquidación espera aquí hasta que se importen los cargos sueltos que pagó, y entonces se convierte en una cadena de verdad. Las demás se pueden descartar sin problema, y en tu contabilidad no cambia nada en ninguno de los dos casos.',
];
