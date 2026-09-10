<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Les comptes à partir desquels le solde quotidien projeté est calculé. La colonne voisine, « :entries », décide autre chose — si les paiements de ce compte sont dessinés dans la grille — de sorte qu’un compte peut être affiché sans compter, ou compté sans être affiché. Là où les deux divergent, le jour le dit sous son solde, plutôt que de laisser un chiffre se construire discrètement à partir de moins que ce que vous attendez.',
];
