<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Abstimmen heißt, Beatrax mit der Zahl deiner Bank zu vergleichen. Der abgeglichene Saldo ist der Anfangssaldo dieses Kontos plus jede Zeile, die du bis zum Auszugsdatum als abgeglichen markiert hast, und die Differenz ist die Zahl auf deinem Auszug abzüglich davon. Setze in der Umsatzliste Häkchen oder nimm sie weg, bis die Differenz null ist — dieser Bildschirm erfindet nie eine Ausgleichsbuchung. „:complete“ sperrt danach die erfassten Zeilen: eine gesperrte Zeile lässt sich nicht mehr bearbeiten, teilen oder löschen, bis du sie auf ihrer eigenen Seite wieder freigibst.',
];
