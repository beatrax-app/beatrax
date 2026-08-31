<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'At afstemme er at holde Beatrax op mod bankens eget tal. Den afstemte saldo er kontoens startsaldo plus hver linje, du har markeret som afstemt til og med kontoudtogets dato, og forskellen er tallet på dit kontoudtog minus den saldo. Sæt eller fjern flueben på linjerne i posteringslisten, indtil forskellen rammer nul — dette skærmbillede opfinder aldrig en udligningspostering. ”:complete” låser derefter de linjer, den dækker: en låst linje kan ikke redigeres, opdeles eller slettes, før du låser den op igen fra dens egen side.',
];
