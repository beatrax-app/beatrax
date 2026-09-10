<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Odsouhlasit znamená porovnat Beatrax s číslem samotné banky. Odsouhlasený zůstatek je počáteční zůstatek tohoto účtu plus každý řádek, který jsi až k datu výpisu označil jako vyrovnaný, a rozdíl je číslo z tvého výpisu minus tento zůstatek. Zaškrtávej nebo odškrtávej řádky v seznamu transakcí, dokud rozdíl nebude nula — tato obrazovka nikdy nevymyslí vyrovnávací zápis. „:complete“ pak zamkne řádky, které pokrývá: zamčený řádek nelze upravit, rozdělit ani smazat, dokud ho na jeho vlastní stránce znovu neodemkneš.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Jak je řádek na tom oproti tvému bankovnímu výpisu. „:uncleared“ znamená, že Beatrax transakci má, ale ty jsi ji zatím k výpisu nepřiřadil; klepni na štítek a bude „:cleared“ — právě tato zaškrtnutí obrazovka odsouhlasení sčítá. Na „:reconciled“ klepnout nejde: nastaví ho dokončené odsouhlasení, které řádek zamkne, takže kategorie, poznámka, rozdělení i daňové štítky zůstanou tak, jak jsou, dokud ho zase neodemkneš.',
];
