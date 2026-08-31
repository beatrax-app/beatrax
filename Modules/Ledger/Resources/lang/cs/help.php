<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Odsouhlasit znamená porovnat Beatrax s číslem samotné banky. Odsouhlasený zůstatek je počáteční zůstatek tohoto účtu plus každý řádek, který jsi až k datu výpisu označil jako vyrovnaný, a rozdíl je číslo z tvého výpisu minus tento zůstatek. Zaškrtávej nebo odškrtávej řádky v seznamu transakcí, dokud rozdíl nebude nula — tato obrazovka nikdy nevymyslí vyrovnávací zápis. „:complete“ pak zamkne řádky, které pokrývá: zamčený řádek nelze upravit, rozdělit ani smazat, dokud ho na jeho vlastní stránce znovu neodemkneš.',
];
