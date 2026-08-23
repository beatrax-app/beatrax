<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPal-eksporter inneholder ingen saldolinjer, så angi dette manuelt.',
    'help_asn' => 'Automatisk forankret ut fra den siste kontoutskriften din. Overstyr bare hvis du vet at den faktiske saldoen avviker.',
    'help_default' => 'Overstyr bare hvis du vet at den faktiske saldoen avviker fra det Beatrax beregner.',

    'legend' => 'Inngående saldo for prognosen for :name',
    'opening_label' => 'Inngående saldo',
    'opening_placeholder' => 'f.eks. 1.250,00',
    'as_of_label' => 'Inngående saldo per',
    'as_of_help' => 'Datoen tallet ovenfor gjelder for.',

    'divergence' => 'Dette avviker med mer enn €500 fra saldoen Beatrax beregner ut fra de importerte transaksjonene dine. Er du sikker?',
    'use_beatrax' => 'Bruk tallet fra Beatrax',
    'use_mine' => 'Bruk mitt tall',

    'save' => 'Lagre inngående saldo',
    'remove' => 'Fjern inngående saldo',
    'saved' => 'Lagret.',
    'removed' => 'Fjernet.',

    'toast' => [
        'updated' => 'Inngående saldo er oppdatert.',
        'removed' => 'Inngående saldo er fjernet.',
    ],

    'errors' => [
        'invalid_number' => 'Inngående saldo må være et gyldig tall.',
        'date_required' => 'Velg datoen denne inngående saldoen gjelder for.',
        'date_invalid' => 'Datoen for inngående saldo må være en gyldig ISO-dato (YYYY-MM-DD).',
        'date_future' => 'Datoen for inngående saldo kan ikke ligge i fremtiden.',
    ],
];
