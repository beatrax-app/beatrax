<?php

declare(strict_types=1);

return [
    'rate_details' => 'Kurso informacija',
    'rate_details_for' => ':name kurso informacija',

    'converted_to' => 'Perskaičiuota į :currency',
    'as_of' => ':date duomenimis',
    'as_of_age' => ':date (:ago)',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'kursai :date duomenimis iš :source',
    'rates_not_recorded' => 'kursai neįrašyti — šis rezultatas išsaugotas be jų',

    // i18n-review: lt · stale_bundled, stale_old, stale_offline — the arms follow the
    // genitive the existing line already used after "senesnis nei", giving "dienos"
    // at one and "dienų" elsewhere. A Lithuanian reader decides whether "senesnis
    // kaip" with the nominative is the form they would write.
    'stale_bundled' => 'Naudojamas kartu pateiktas kursų momentinis vaizdas, senesnis nei :count dienos. Įjunk atnaujinimą internetu Nustatymuose, kad matytum dabartinius kursus.|Naudojamas kartu pateiktas kursų momentinis vaizdas, senesnis nei :count dienų. Įjunk atnaujinimą internetu Nustatymuose, kad matytum dabartinius kursus.|Naudojamas kartu pateiktas kursų momentinis vaizdas, senesnis nei :count dienų. Įjunk atnaujinimą internetu Nustatymuose, kad matytum dabartinius kursus.',
    'stale_old' => 'Šis kursas senesnis nei :count dienos. Kitas atnaujinimas internetu jį atnaujins.|Šis kursas senesnis nei :count dienų. Kitas atnaujinimas internetu jį atnaujins.|Šis kursas senesnis nei :count dienų. Kitas atnaujinimas internetu jį atnaujins.',
    'stale_offline' => 'Šis kursas senesnis nei :count dienos, o atnaujinimas internetu išjungtas. Įjunk jį Nustatymuose, kad kursas būtų atnaujintas.|Šis kursas senesnis nei :count dienų, o atnaujinimas internetu išjungtas. Įjunk jį Nustatymuose, kad kursas būtų atnaujintas.|Šis kursas senesnis nei :count dienų, o atnaujinimas internetu išjungtas. Įjunk jį Nustatymuose, kad kursas būtų atnaujintas.',

    'source_ecb' => 'ECB',
    'source_bundled' => 'Kartu pateiktas momentinis vaizdas',
    'source_transaction' => 'Užfiksuotas kursas',
    'source_fallback' => 'kursai',
];
