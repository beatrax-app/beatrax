<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Un salvadanaio mette da parte del denaro dentro un conto invece di portarlo fuori, quindi il saldo in banca non cambia quando lo alimenti o ne prelevi. Ogni conto mostra tre cifre: “:real” è quanto tiene la banca, “:allocated” è quanto i tuoi salvadanai hanno rivendicato in tutto, e “:unallocated” è ciò che avanza — l’unica parte ancora libera da spendere. Quando quest’ultima cifra scende sotto zero i salvadanai rivendicano più di quanto il conto contenga, e ogni saldo è sovrastimato finché non ne riprendi una parte.',
];
