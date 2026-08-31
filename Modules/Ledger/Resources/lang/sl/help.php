<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Uskladitev pomeni primerjavo Beatraxa s številko same banke. Usklajeno stanje je začetno stanje tega računa plus vsaka vrstica, ki si jo do datuma izpiska označil kot poravnano, razlika pa je številka s tvojega izpiska minus to stanje. Označuj ali odznačuj vrstice na seznamu transakcij, dokler razlika ne pade na nič — ta zaslon nikoli ne izmisli izravnalnega vpisa. „:complete“ nato zaklene zajete vrstice: zaklenjene vrstice ni mogoče urejati, deliti ali izbrisati, dokler je na njeni strani znova ne odkleneš.',
];
