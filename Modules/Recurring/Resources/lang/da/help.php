<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Et kontoudtog er en flad liste med datoer og beløb, og intet i den siger, hvilke linjer der er den samme faste forpligtelse. Beatrax grupperer linjerne efter, hvem der blev betalt, kasserer de beløb, der falder uden for gruppen, og foreslår først en serie, når mellemrummene lægger sig i en fast ugentlig, månedlig, kvartalsvis eller årlig rytme — alt mindre regelmæssigt bliver aldrig foreslået. Den kigger kun så langt tilbage som ”:setting” under Indstillinger, og det starter på den korteste periode, den kan arbejde med, så en årlig regning er usynlig, indtil du udvider den. Intet her ændrer dine data, før du godkender det.',
];
