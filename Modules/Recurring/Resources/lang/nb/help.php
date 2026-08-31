<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'En kontoutskrift er en flat liste med datoer og beløp, og ingenting i den sier hvilke linjer som er den samme faste forpliktelsen. Beatrax grupperer linjene etter hvem som ble betalt, forkaster beløpene som faller utenfor gruppen, og foreslår først en serie når mellomrommene legger seg i en fast ukentlig, månedlig, kvartalsvis eller årlig rytme — alt mindre regelmessig blir aldri foreslått. Den ser bare så langt tilbake som ”:setting” under Innstillinger, og det starter på den korteste perioden den kan jobbe med, så en årlig regning er usynlig til du utvider den. Ingenting her endrer dataene dine før du godkjenner det.',
];
