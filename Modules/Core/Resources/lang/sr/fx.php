<?php

declare(strict_types=1);

return [
    'rate_details' => 'Detalji kursa',
    'rate_details_for' => 'Detalji kursa za :name',

    'converted_to' => 'Preračunato u :currency',
    'as_of' => 'na dan :date',
    'as_of_age' => ':date (:ago)',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'kursevi na dan :date iz izvora :source',

    'stale_bundled' => 'Koristi se kurs iz ugrađenog snimka, stariji od :count dana. Uključi onlajn osvežavanje u Podešavanjima za aktuelne kurseve.|Koristi se kurs iz ugrađenog snimka, stariji od :count dana. Uključi onlajn osvežavanje u Podešavanjima za aktuelne kurseve.|Koristi se kurs iz ugrađenog snimka, stariji od :count dana. Uključi onlajn osvežavanje u Podešavanjima za aktuelne kurseve.',
    'stale_old' => 'Ovaj kurs je stariji od :count dana. Sledeće onlajn osvežavanje će ga ažurirati.|Ovaj kurs je stariji od :count dana. Sledeće onlajn osvežavanje će ga ažurirati.|Ovaj kurs je stariji od :count dana. Sledeće onlajn osvežavanje će ga ažurirati.',
    'stale_offline' => 'Ovaj kurs je stariji od :count dana, a onlajn osvežavanje je isključeno. Uključi ga u Podešavanjima da bi se kurs ažurirao.|Ovaj kurs je stariji od :count dana, a onlajn osvežavanje je isključeno. Uključi ga u Podešavanjima da bi se kurs ažurirao.|Ovaj kurs je stariji od :count dana, a onlajn osvežavanje je isključeno. Uključi ga u Podešavanjima da bi se kurs ažurirao.',

    'source_ecb' => 'ECB',
    'source_bundled' => 'Ugrađeni snimak',
    'source_transaction' => 'Zabeleženi kurs',
    'source_fallback' => 'kursevi',
];
