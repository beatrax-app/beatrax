<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal-eksporter indeholder ingen saldolinjer, så angiv det manuelt.',
    'help_asn' => 'Automatisk forankret ud fra dit seneste kontoudtog. Tilsidesæt kun, hvis du ved, at den reelle saldo afviger.',
    'help_default' => 'Tilsidesæt kun, hvis du ved, at den aktuelle saldo afviger fra det, Beatrax beregner.',

    'legend' => 'Startsaldo for prognosen for :name',
    'opening_label' => 'Startsaldo',
    'opening_placeholder' => 'f.eks. 1.250,00',
    'as_of_label' => 'Startsaldo pr.',
    'as_of_help' => 'Den dato, tallet ovenfor gælder for.',

    'divergence' => 'Det afviger med mere end €500 fra den saldo, Beatrax beregner ud fra dine importerede transaktioner. Er du sikker?',
    'use_beatrax' => 'Brug tallet fra Beatrax',
    'use_mine' => 'Brug mit tal',

    'save' => 'Gem startsaldo',
    'saved' => 'Gemt.',

    'toast' => [
        'updated' => 'Startsaldoen er opdateret.',
    ],

    'errors' => [
        'invalid_number' => 'Startsaldoen skal være et gyldigt tal.',
        'date_required' => 'Vælg den dato, denne startsaldo gælder for.',
        'date_invalid' => 'Datoen for startsaldoen skal være en gyldig ISO-dato (YYYY-MM-DD).',
        'date_future' => 'Datoen for startsaldoen kan ikke ligge i fremtiden.',
    ],
];
