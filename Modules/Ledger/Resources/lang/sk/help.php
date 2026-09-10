<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Odsúhlasiť znamená porovnať Beatrax s číslom samotnej banky. Odsúhlasený zostatok je počiatočný zostatok tohto účtu plus každý riadok, ktorý si až k dátumu výpisu označil ako vyrovnaný, a rozdiel je číslo z tvojho výpisu mínus tento zostatok. Zaškrtávaj alebo odškrtávaj riadky v zozname transakcií, kým rozdiel nebude nula — táto obrazovka nikdy nevymyslí vyrovnávací zápis. „:complete“ potom zamkne riadky, ktoré pokrýva: zamknutý riadok sa nedá upraviť, rozdeliť ani vymazať, kým ho na jeho vlastnej stránke znova neodomkneš.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Ako je riadok na tom oproti tvojmu bankovému výpisu. „:uncleared“ znamená, že Beatrax transakciu má, ale ty si ju k výpisu ešte nepriradil; ťukni na štítok a bude „:cleared“ — práve tieto zaškrtnutia obrazovka odsúhlasenia spočíta. Na „:reconciled“ sa ťuknúť nedá: nastaví ho dokončené odsúhlasenie, ktoré riadok zamkne, takže kategória, poznámka, rozdelenie aj daňové štítky zostanú tak, ako sú, kým ho zase neodomkneš.',
];
