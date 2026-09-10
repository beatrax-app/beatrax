<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Jedna płatność często płaci za kilka innych: rozliczenie karty na koncie bankowym pokrywa miesiąc zakupów kartą, a wypłata z banku finansuje płatność portfelem sprzed kilku dni. Łańcuch zapisuje, które obciążenie za co zapłaciło, dzięki czemu zakup z jednego wyciągu można prześledzić aż do pieniędzy, które naprawdę opuściły konto. Beatrax sam łączy przypadki pewne, a resztę zostawia w kolejce do przejrzenia. Potwierdź kilka razy ten sam rodzaj powiązania, a przestanie o niego pytać.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Wskazówka to pół powiązania: Beatrax znalazł jedną stronę łańcucha płatności, a drugiej nie, więc zapisał to, co zobaczył, zamiast zgadywać resztę. Większość rozwiązuje się sama — rozliczenie czeka tutaj, aż zostaną zaimportowane pojedyncze obciążenia, które opłaciło, i wtedy staje się prawdziwym łańcuchem. Resztę możesz spokojnie odrzucić; w twoich zapisach tak czy owak nic się nie zmienia.',
];
