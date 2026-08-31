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

    'chain' => [
        'heading' => 'Nustatomos grandinės…',
        'pending' => 'Eilėje. Grandinių nustatymas netrukus prasidės.',
        'running' => 'Siejamos lėšų grandinės ir skaidomi išrašo atsiskaitymai.',
    ],

    'issues' => [
        'row' => 'Eilutė :row: :reason',
        'file_stopped' => 'Failo nepavyko perskaityti toliau nei :row eilutė. Niekas po tos eilutės nebuvo importuota.',
        'file_none' => 'Failo išvis nepavyko perskaityti.',
        'detail' => 'Skaitytuvas pranešė: :reason',
        'duplicate' => 'Eilutė :row jau buvo tavo knygoje.',
        'more' => '+ :count nenurodyta',
    ],
];
