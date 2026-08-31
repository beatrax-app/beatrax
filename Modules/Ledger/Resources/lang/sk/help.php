<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Odsúhlasiť znamená porovnať Beatrax s číslom samotnej banky. Odsúhlasený zostatok je počiatočný zostatok tohto účtu plus každý riadok, ktorý si až k dátumu výpisu označil ako vyrovnaný, a rozdiel je číslo z tvojho výpisu mínus tento zostatok. Zaškrtávaj alebo odškrtávaj riadky v zozname transakcií, kým rozdiel nebude nula — táto obrazovka nikdy nevymyslí vyrovnávací zápis. „:complete“ potom zamkne riadky, ktoré pokrýva: zamknutý riadok sa nedá upraviť, rozdeliť ani vymazať, kým ho na jeho vlastnej stránke znova neodomkneš.',
];
