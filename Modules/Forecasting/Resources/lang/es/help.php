<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'A qué cuenta se imputa un pago previsto. Algunos pagos nunca salen de la cuenta de la que parecen salir: una compra con tarjeta se liquida más tarde con un solo cargo en tu cuenta bancaria, y un pago de monedero se financia con una transferencia. Con esto activado, cada uno de ellos se imputa a la cuenta que realmente lo paga, de modo que la línea de una cuenta muestra el dinero que de verdad pasará por ella y no el que solo pasó de largo.',
];
