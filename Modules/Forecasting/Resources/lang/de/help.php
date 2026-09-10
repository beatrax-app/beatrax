<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Welchem Konto eine prognostizierte Zahlung zugerechnet wird. Manche Zahlungen verlassen nie das Konto, von dem sie zu kommen scheinen: ein Karteneinkauf wird später mit einer einzigen Buchung von deinem Bankkonto ausgeglichen, und eine Wallet-Zahlung wird durch eine Überweisung gedeckt. Ist dies eingeschaltet, wird jede davon dem Konto zugerechnet, das sie tatsächlich bezahlt, sodass die Linie eines Kontos das Geld zeigt, das wirklich durch es fließt, und nicht das, was nur vorbeikam.',
];
