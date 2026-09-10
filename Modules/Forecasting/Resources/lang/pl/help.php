<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Do którego konta liczy się prognozowana płatność. Niektóre płatności nigdy nie opuszczają konta, z którego zdają się wychodzić: zakup kartą rozlicza się później jednym obciążeniem twojego konta bankowego, a płatność z portfela zasila przelew. Po włączeniu każda z nich liczy się do konta, które naprawdę ją opłaca, więc linia konta pokazuje pieniądze, które rzeczywiście przez nie przejdą, a nie te, które jedynie przeszły obok.',
];
