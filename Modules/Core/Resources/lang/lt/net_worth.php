<?php

declare(strict_types=1);

return [
    'aria' => 'Grynoji vertė',
    'heading' => 'Grynoji vertė',

    'rate_details' => 'Kurso informacija',
    'rate_details_for' => ':name kurso informacija',

    'across' => 'iš :count sąskaitos|iš :count sąskaitų|iš :count sąskaitų',

    'not_converted' => '· :count sąskaita neperskaičiuota — kurso nėra|· :count sąskaitos neperskaičiuotos — kurso nėra|· :count sąskaitų neperskaičiuota — kurso nėra',
    'no_rate_available' => '· kurso nėra',

    'toggle_hide' => 'Slėpti',
    'toggle_breakdown' => 'Išskaidymas',
    'card_suffix' => '(kortelė)',

    'converted_to' => 'Perskaičiuota į :currency',
    'as_of' => ':date duomenimis',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'kursai :date duomenimis iš :source',

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
