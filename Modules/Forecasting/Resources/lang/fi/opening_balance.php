<?php

declare(strict_types=1);

return [
    'help_paypal' => 'PayPalin vienneissä ei ole saldorivejä, joten aseta tämä käsin.',
    'help_default' => 'Korvaa vain, jos tiedät nykyisen todellisen saldon poikkeavan siitä, minkä Beatrax laskee.',

    'legend' => 'Ennusteen alkusaldo tilille :name',
    'opening_label' => 'Alkusaldo',
    'opening_placeholder' => 'esim. :amount',
    'as_of_label' => 'Alkusaldon päivämäärä',
    'as_of_help' => 'Päivä, jolta yllä oleva luku pitää paikkansa.',

    'divergence' => 'Tämä poikkeaa yli :threshold siitä saldosta, jonka Beatrax laskee tuoduista tapahtumistasi. Oletko varma?',
    'computed_is' => 'Beatrax laskee :amount.',
    'use_beatrax' => 'Käytä Beatraxin lukua',
    'use_mine' => 'Käytä omaa lukuani',

    'save' => 'Tallenna alkusaldo',
    'remove' => 'Poista alkusaldo',
    'saved' => 'Tallennettu.',
    'removed' => 'Poistettu.',

    'toast' => [
        'updated' => 'Alkusaldo päivitetty.',
        'removed' => 'Alkusaldo poistettu.',
    ],

    'errors' => [
        'invalid_number' => 'Alkusaldon on oltava kelvollinen luku.',
        'date_required' => 'Valitse päivä, jota tämä alkusaldo koskee.',
        'date_invalid' => 'Alkusaldon päivämäärän on oltava kelvollinen ISO-päivämäärä (YYYY-MM-DD).',
        'date_future' => 'Alkusaldon päivämäärä ei voi olla tulevaisuudessa.',
    ],
];
