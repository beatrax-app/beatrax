<?php

declare(strict_types=1);

return [
    'page_title' => 'Ta naprava je sinhronizirana',
    'heading' => 'Ta naprava je sinhronizirana',
    // i18n-review: sl · records — rewritten from a count label so the dual is
    // visible ("sta kopirana 2 zapisa"). Leading with :peer is a guess about what
    // reads well when the device name is long.
    'records' => 'Iz naprave :peer je kopiran :count zapis.|Iz naprave :peer sta kopirana :count zapisa.|Iz naprave :peer so kopirani :count zapisi.|Iz naprave :peer je kopiranih :count zapisov.',
    'records_none' => 'Vse je usklajeno — nič novega ni bilo za kopiranje. Izvorna naprava: :peer.',
    'how_it_works' => 'Od zdaj naprej',
    'automatic_title' => 'Ti izbereš, kdaj se sinhronizira',
    'automatic_body' => 'Karkoli spremeniš na eni napravi, se pokaže na drugi, ko se naslednjič dotakneš :action. V ozadju ne more teči — zaklep aplikacije hrani edini ključ.',
    'lan_title' => 'V istem omrežju',
    'lan_body' => 'Ko sta obe napravi v tvojem domačem omrežju, se pogovarjata neposredno, brez česar koli vmes.',
    'relay_title' => 'Ko te ni doma',
    'relay_body' => 'Spremembe šifrirane čakajo na tvojem releju, dokler se druga naprava znova ne poveže. Ta naprava jih prevzame, ko se naslednjič dotakneš :action.',
    'no_relay_title' => 'Ko te ni doma',
    'no_relay_body' => 'Spremembe čakajo na tej napravi, dokler nista obe skupaj v tvojem domačem omrežju in se tu ne dotakneš :action.',
    'encrypted_title' => 'Bere lahko samo tvoje naprave',
    'encrypted_body' => 'Vse je šifrirano, preden zapusti napravo, ključe pa imajo samo tvoje seznanjene naprave.',
    'continue' => 'Začni uporabljati Beatrax',
    'peer_fallback' => 'tvoja druga naprava',
];
