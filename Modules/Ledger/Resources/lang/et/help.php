<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Vastavusse viimine tähendab Beatraxi võrdlemist panga enda numbriga. Kontrollitud saldo on selle konto algsaldo pluss iga rida, mille oled kuni väljavõtte kuupäevani kontrollituks märkinud, ja vahe on sinu väljavõtte number miinus see saldo. Märgi tehingute loendis ridu või võta märge maha, kuni vahe jõuab nulli — see vaade ei leiuta kunagi tasakaalustavat kannet. „:complete“ lukustab seejärel need read, mida ta katab: lukustatud rida ei saa muuta, tükeldada ega kustutada enne, kui avad selle uuesti tema enda lehelt.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Kus rida sinu pangaväljavõtte suhtes seisab. „:uncleared“ tähendab, et Beatraxil on tehing olemas, kuid sa pole seda veel väljavõttega kokku viinud; puuduta silti, et see saaks „:cleared“ — just neid linnukesi kooskõlastuse ekraan kokku liidab. „:reconciled“ ei ole puudutatav: selle paneb lõpetatud kooskõlastus, mis rea lukustab, nii et kategooria, märkus, jaotus ja maksumärgised jäävad paigale, kuni sa selle uuesti avad.',
];
