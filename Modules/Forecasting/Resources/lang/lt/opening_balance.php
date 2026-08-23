<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal eksportuose likučio eilučių nėra, todėl nurodyk jį ranka.',
    'help_asn' => 'Automatiškai nustatyta pagal naujausią tavo sąskaitos išrašą. Keisk tik tada, jei žinai, kad tikrasis likutis skiriasi.',
    'help_default' => 'Keisk tik tada, jei žinai, kad dabartinis tikrasis likutis skiriasi nuo to, kurį apskaičiuoja Beatrax.',

    'legend' => 'Prognozės pradinis :name likutis',
    'opening_label' => 'Pradinis likutis',
    'opening_placeholder' => 'pvz. 1.250,00',
    'as_of_label' => 'Pradinis likutis datai',
    'as_of_help' => 'Data, kuriai pirmiau nurodytas skaičius yra teisingas.',

    'divergence' => 'Tai daugiau nei 500 € skiriasi nuo likučio, kurį Beatrax apskaičiuoja iš tavo importuotų operacijų. Ar tikrai?',
    'use_beatrax' => 'Naudoti Beatrax skaičių',
    'use_mine' => 'Naudoti mano skaičių',

    'save' => 'Išsaugoti pradinį likutį',
    'remove' => 'Pašalinti pradinį likutį',
    'saved' => 'Išsaugota.',
    'removed' => 'Pašalinta.',

    'toast' => [
        'updated' => 'Pradinis likutis atnaujintas.',
        'removed' => 'Pradinis likutis pašalintas.',
    ],

    'errors' => [
        'invalid_number' => 'Pradinis likutis turi būti tinkamas skaičius.',
        'date_required' => 'Pasirink datą, kuriai galioja šis pradinis likutis.',
        'date_invalid' => 'Pradinio likučio data turi būti tinkama ISO data (YYYY-MM-DD).',
        'date_future' => 'Pradinio likučio data negali būti ateityje.',
    ],
];
