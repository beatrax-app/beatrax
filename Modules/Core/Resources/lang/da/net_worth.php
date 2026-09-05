<?php

declare(strict_types=1);

return [
    'aria' => 'Nettoformue',
    'heading' => 'Nettoformue',

    'rate_details' => 'Kursdetaljer',
    'rate_details_for' => 'Kursdetaljer for :name',

    'across' => 'fordelt på :count konto|fordelt på :count konti',

    'not_converted' => '· :count konto blev ikke omregnet — ingen kurs tilgængelig|· :count konti blev ikke omregnet — ingen kurs tilgængelig',
    'no_rate_available' => '· ingen kurs tilgængelig',

    'toggle_hide' => 'Skjul',
    'toggle_breakdown' => 'Fordeling',
    'card_suffix' => '(kort)',

    'converted_to' => 'Omregnet til :currency',
    'as_of' => 'pr. :date',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'kurser pr. :date fra :source',

    'stale_bundled' => 'Der bruges en medfølgende øjebliksbilledkurs, der er mere end :count dag gammel. Slå onlineopdatering til under Indstillinger for aktuelle kurser.|Der bruges en medfølgende øjebliksbilledkurs, der er mere end :count dage gammel. Slå onlineopdatering til under Indstillinger for aktuelle kurser.',
    'stale_old' => 'Denne kurs er mere end :count dag gammel. Den opdateres ved næste onlineopdatering.|Denne kurs er mere end :count dage gammel. Den opdateres ved næste onlineopdatering.',
    'stale_offline' => 'Denne kurs er mere end :count dag gammel, og onlineopdatering er slået fra. Slå den til under Indstillinger for at opdatere kursen.|Denne kurs er mere end :count dage gammel, og onlineopdatering er slået fra. Slå den til under Indstillinger for at opdatere kursen.',

    'source_ecb' => 'ECB',
    'source_bundled' => 'Medfølgende øjebliksbillede',
    'source_transaction' => 'Registreret kurs',
    'source_fallback' => 'kurser',
];
