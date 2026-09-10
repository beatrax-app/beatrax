<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Å avstemme er å holde Beatrax opp mot bankens eget tall. Den avstemte saldoen er startsaldoen på denne kontoen pluss hver linje du har haket av som avstemt til og med kontoutskriftens dato, og differansen er tallet på kontoutskriften minus den saldoen. Hak av eller fjern haken på linjer i transaksjonslisten til differansen blir null — denne skjermen finner aldri opp en utligningspostering. ”:complete” låser deretter linjene den dekker: en låst linje kan ikke redigeres, deles eller slettes før du låser den opp igjen fra dens egen side.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Hvor en rad står i forhold til kontoutskriften din. ”:uncleared” betyr at Beatrax har transaksjonen, men at du ennå ikke har koblet den til en utskrift; trykk på pillen for å gjøre den ”:cleared”, og det er nettopp de hakene avstemmingssiden summerer. ”:reconciled” kan du ikke trykke på — den settes av en fullført avstemming, som låser raden, så kategori, notat, oppdeling og skattemerker blir stående til du låser opp igjen.',
];
