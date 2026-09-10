<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Riconciliare significa confrontare Beatrax con il dato della banca. Il saldo riconciliato è il saldo iniziale di questo conto più ogni riga che hai spuntato come riconciliata fino alla data dell’estratto, e la differenza è il dato del tuo estratto meno quel saldo. Spunta o togli la spunta alle righe nell’elenco dei movimenti finché la differenza non arriva a zero: questa schermata non inventa mai una scrittura di quadratura. “:complete” blocca poi le righe che copre: una riga bloccata non si può modificare, dividere né eliminare finché non la sblocchi dalla sua pagina.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'A che punto è una riga rispetto al tuo estratto conto. “:uncleared” vuol dire che Beatrax ha il movimento ma tu non l’hai ancora confrontato con un estratto; tocca la pillola per portarlo a “:cleared”, e sono proprio quelle spunte che la schermata di riconciliazione somma. “:reconciled” non si tocca: lo imposta una riconciliazione completata, che blocca la riga — categoria, nota, suddivisione ed etichette fiscali restano come sono finché non la sblocchi.',
];
