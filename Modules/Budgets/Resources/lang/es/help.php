<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Dinero que ya ha entrado y todavía no tiene sobre: los ingresos de este periodo, más lo que quedó sin asignar en el periodo anterior, menos todo lo asignado más abajo. Llévalo a cero y no queda nada sin planificar. Por debajo de cero has asignado más de lo que realmente ha entrado: saca algo de un sobre o espera a la próxima nómina.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Lo que le ocurre a un sobre que ha gastado más de lo que contiene, una vez termina el periodo. Con “:reduce”, el descubierto se descuenta de lo primero que tendrás para repartir el periodo siguiente y el sobre vuelve a empezar en cero. Con “:carry”, el descubierto se queda donde se produjo: ese sobre abre por debajo de cero y hay que rellenarlo antes de que vuelva a pagar nada, y el resto del plan no se toca.',
];
