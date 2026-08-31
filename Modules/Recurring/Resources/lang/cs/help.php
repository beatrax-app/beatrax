<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Výpis je plochý seznam dat a částek a nic v něm neříká, které řádky jsou stejný trvalý závazek. Beatrax seskupí řádky podle příjemce platby, zahodí částky, které ze skupiny vybočují, a sérii navrhne teprve tehdy, když se rozestupy mezi nimi ustálí v pravidelném týdenním, měsíčním, čtvrtletním nebo ročním rytmu — cokoli méně pravidelného nenavrhne vůbec. Dozadu čte jen tak daleko, kam sahá „:setting“ v Nastavení, a to začíná na nejkratším úseku, se kterým vůbec umí pracovat, takže roční faktura zůstane mimo dohled, dokud ho nerozšíříš. S tvými daty se tu nic neděje, dokud to neschválíš.',
];
