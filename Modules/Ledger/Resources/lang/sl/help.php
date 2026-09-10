<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Uskladitev pomeni primerjavo Beatraxa s številko same banke. Usklajeno stanje je začetno stanje tega računa plus vsaka vrstica, ki si jo do datuma izpiska označil kot poravnano, razlika pa je številka s tvojega izpiska minus to stanje. Označuj ali odznačuj vrstice na seznamu transakcij, dokler razlika ne pade na nič — ta zaslon nikoli ne izmisli izravnalnega vpisa. „:complete“ nato zaklene zajete vrstice: zaklenjene vrstice ni mogoče urejati, deliti ali izbrisati, dokler je na njeni strani znova ne odkleneš.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Kje vrstica stoji glede na tvoj bančni izpisek. „:uncleared“ pomeni, da Beatrax transakcijo ima, ti pa je še nisi ujel z izpiskom; tapni oznako, da postane „:cleared“ — prav te kljukice zaslon usklajevanja sešteje. „:reconciled“ ni mogoče tapniti: postavi ga dokončano usklajevanje, ki vrstico zaklene, zato kategorija, opomba, razdelitev in davčne oznake ostanejo take, kot so, dokler je spet ne odkleneš.',
];
