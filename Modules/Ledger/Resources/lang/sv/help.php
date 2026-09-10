<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Att stämma av är att hålla Beatrax mot bankens egen siffra. Det avstämda saldot är kontots ingående saldo plus varje rad du har bockat för som avstämd fram till kontoutdragets datum, och differensen är siffran på ditt kontoutdrag minus det saldot. Bocka för eller av rader i transaktionslistan tills differensen når noll — den här vyn hittar aldrig på en utjämningspost. ”:complete” låser sedan de rader den omfattar: en låst rad går inte att redigera, dela eller ta bort förrän du låser upp den igen från dess egen sida.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Var en rad står i förhållande till ditt kontoutdrag. ”:uncleared” betyder att Beatrax har transaktionen men att du ännu inte har stämt av den mot ett utdrag; tryck på pillret för att göra den ”:cleared”, och det är just de bockarna avstämningssidan summerar. ”:reconciled” går inte att trycka på — den sätts av en avslutad avstämning, som låser raden, så kategori, anteckning, uppdelning och skatteetiketter står kvar tills du låser upp den igen.',
];
