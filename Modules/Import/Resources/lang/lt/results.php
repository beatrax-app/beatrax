<?php

declare(strict_types=1);

return [
    'page_title' => 'Importas baigtas',
    'heading' => 'Importas baigtas',

    'summary' => 'Importuota :count operacija|Importuotos :count operacijos|Importuota :count operacijų',
    'summary_duplicates' => ' · praleistas :count dublikatas| · praleisti :count dublikatai| · praleista :count dublikatų',
    'summary_enriched' => ' · papildyta: :count',
    'summary_errors' => ' · :count klaida| · :count klaidos| · :count klaidų',

    'show_duplicates' => 'Rodyti praleistus dublikatus (:count)',
    'duplicates_help' => 'Dublikatai — eilutės, kurios tavo didžiojoje knygoje jau yra; importuojant pakartotinai jos tyliai praleidžiamos.',
    'show_errors' => 'Rodyti klaidas (:count)',
    'errors_help' => 'Klaidos — eilutės, kurių nepavyko nuskaityti; į didžiąją knygą jos neįtrauktos.',

    'upload_another' => 'Įkelti kitą išrašą',

    'issues' => [
        'row' => 'Eilutė :row: :reason',
        'file' => 'Nepavyko perskaityti viso failo: :reason',
        'duplicate' => 'Eilutė :row jau buvo tavo knygoje.',
        'more' => '+ :count nenurodyta',
        'unknown_reason' => 'Priežastis neužfiksuota.',
    ],
];
