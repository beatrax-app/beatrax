<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Pieniądze, które już wpłynęły i nie mają jeszcze koperty: przychody z tego okresu, plus to, co zostało nierozdzielone w poprzednim okresie, minus wszystko rozdzielone poniżej. Sprowadź to do zera, a nic nie zostanie bez planu. Poniżej zera oznacza, że rozdzielono więcej, niż faktycznie wpłynęło — zabierz coś z jednej z kopert albo poczekaj na następną wypłatę.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Co dzieje się z kopertą, która wydała więcej, niż w niej jest, gdy okres się kończy. Przy „:reduce” niedobór odejmowany jest w pierwszej kolejności od tego, co masz do rozdzielenia w następnym okresie, a sama koperta zaczyna od zera. Przy „:carry” niedobór zostaje tam, gdzie powstał: ta koperta otwiera się poniżej zera i trzeba ją uzupełnić, zanim za cokolwiek zapłaci, a reszta planu pozostaje nietknięta.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'available' => 'Co ta koperta może jeszcze zapłacić: „:assigned” w tym okresie, plus „:carried”, plus lub minus „:moved”, minus „:spent” — cztery kolumny po lewej. To nie jest saldo konta: z jednego konta czerpie kilka kopert, a ta liczba mówi tylko o tej jednej. Poniżej zera koperta wydała już więcej, niż w niej jest, a koniec okresu rozstrzyga, co stanie się z tym niedoborem.',
];
