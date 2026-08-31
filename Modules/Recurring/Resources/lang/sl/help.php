<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Izpisek je ploski seznam datumov in zneskov in nič v njem ne pove, katere vrstice so ista trajna obveznost. Beatrax vrstice združi po prejemniku plačila, zavrže zneske, ki izstopajo iz skupine, in serijo predlaga šele, ko se razmiki med njimi umirijo v enakomeren tedenski, mesečni, četrtletni ali letni ritem — vse manj redno se sploh ne predlaga. Nazaj bere le toliko, kolikor dopušča „:setting“ v nastavitvah, to pa se začne pri najkrajšem obdobju, s katerim sploh zmore delati, zato letni račun ostane neviden, dokler ga ne razširiš. Tvojih podatkov se tu nič ne dotakne, dokler tega ne potrdiš.',
];
