<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Uma reserva põe dinheiro de lado dentro de uma conta em vez de o tirar de lá, por isso o saldo no teu banco não muda quando depositas ou levantas. Cada conta mostra três valores: “:real” é o que o banco tem, “:allocated” é o que as tuas reservas reclamaram no conjunto, e “:unallocated” é o que sobra — a única parte ainda livre para gastar. Quando esse último valor desce abaixo de zero, as reservas reclamam mais do que a conta tem, e todos os saldos ficam sobrevalorizados até retirares algo.',
];
