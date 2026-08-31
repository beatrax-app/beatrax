<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Jedna płatność często płaci za kilka innych: rozliczenie karty na koncie bankowym pokrywa miesiąc zakupów kartą, a wypłata z banku finansuje płatność portfelem sprzed kilku dni. Łańcuch zapisuje, które obciążenie za co zapłaciło, dzięki czemu zakup z jednego wyciągu można prześledzić aż do pieniędzy, które naprawdę opuściły konto. Beatrax sam łączy przypadki pewne, a resztę zostawia w kolejce do przejrzenia. Potwierdź kilka razy ten sam rodzaj powiązania, a przestanie o niego pytać.',
];
