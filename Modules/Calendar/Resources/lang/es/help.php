<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'De qué cuentas se construye el saldo diario proyectado. La columna de al lado, “:entries”, decide otra cosa — si los pagos de esa cuenta se dibujan siquiera en la cuadrícula — así que una cuenta puede verse sin contar, o contar sin verse. Donde las dos no coinciden, el día lo dice bajo su saldo, en vez de dejar que una cifra se arme calladamente con menos de lo que esperas.',
];
