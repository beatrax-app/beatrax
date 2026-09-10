<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'At afstemme er at holde Beatrax op mod bankens eget tal. Den afstemte saldo er kontoens startsaldo plus hver linje, du har markeret som afstemt til og med kontoudtogets dato, og forskellen er tallet på dit kontoudtog minus den saldo. Sæt eller fjern flueben på linjerne i posteringslisten, indtil forskellen rammer nul — dette skærmbillede opfinder aldrig en udligningspostering. ”:complete” låser derefter de linjer, den dækker: en låst linje kan ikke redigeres, opdeles eller slettes, før du låser den op igen fra dens egen side.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Hvor en række står i forhold til dit kontoudtog. ”:uncleared” betyder, at Beatrax har posteringen, men at du endnu ikke har holdt den op mod et udtog; tryk på pillen for at gøre den ”:cleared”, og det er netop de flueben, afstemningsskærmen lægger sammen. ”:reconciled” kan du ikke trykke på — den sættes af en gennemført afstemning, som låser rækken, så kategori, note, opdeling og skattemærker bliver stående, indtil du låser op igen.',
];
