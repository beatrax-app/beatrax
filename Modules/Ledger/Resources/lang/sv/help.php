<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Att stämma av är att hålla Beatrax mot bankens egen siffra. Det avstämda saldot är kontots ingående saldo plus varje rad du har bockat för som avstämd fram till kontoutdragets datum, och differensen är siffran på ditt kontoutdrag minus det saldot. Bocka för eller av rader i transaktionslistan tills differensen når noll — den här vyn hittar aldrig på en utjämningspost. ”:complete” låser sedan de rader den omfattar: en låst rad går inte att redigera, dela eller ta bort förrän du låser upp den igen från dess egen sida.',
];
