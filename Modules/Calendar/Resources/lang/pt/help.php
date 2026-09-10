<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'A partir de que contas é construído o saldo diário projetado. A coluna ao lado, “:entries”, decide outra coisa — se os pagamentos dessa conta são sequer desenhados na grelha — por isso uma conta pode aparecer sem contar, ou contar sem aparecer. Onde as duas divergem, o dia di-lo por baixo do seu saldo, em vez de deixar um valor formar-se em silêncio a partir de menos do que esperas.',
];
