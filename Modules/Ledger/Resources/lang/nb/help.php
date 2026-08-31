<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Å avstemme er å holde Beatrax opp mot bankens eget tall. Den avstemte saldoen er startsaldoen på denne kontoen pluss hver linje du har haket av som avstemt til og med kontoutskriftens dato, og differansen er tallet på kontoutskriften minus den saldoen. Hak av eller fjern haken på linjer i transaksjonslisten til differansen blir null — denne skjermen finner aldri opp en utligningspostering. ”:complete” låser deretter linjene den dekker: en låst linje kan ikke redigeres, deles eller slettes før du låser den opp igjen fra dens egen side.',
];
