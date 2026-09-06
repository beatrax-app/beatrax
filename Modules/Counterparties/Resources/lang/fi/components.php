<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Vastapuolen tyyppi: :type',
        'merchant' => 'Kauppias',
        'personal' => 'Henkilö',
        'bank' => 'Pankki',
        'government' => 'Julkishallinto',
        'self' => 'Oma',
        'unknown' => 'Tuntematon',
    ],

    'filter_chips' => [
        'aria' => 'Suodata tyypin mukaan',
        'all' => 'Kaikki',
        'merchant' => 'Kauppiaat',
        'personal' => 'Henkilöt',
        'bank' => 'Pankit',
        'government' => 'Julkishallinto',
        'self' => 'Oma',
        'unknown' => 'Tuntemattomat',
    ],

    'default_name' => [
        'bank_fee' => 'Pankkikulu',
        'account_maintenance' => 'Tilinhoitomaksu',
        'monthly_fee' => 'Kuukausimaksu',
        'quarterly_fee' => 'Neljännesvuosimaksu',
        'annual_fee' => 'Vuosimaksu',
        'card_fee' => 'Korttimaksu',
        'transaction_fee' => 'Tapahtumamaksu',
        'transfer_fee' => 'Tilisiirtomaksu',
        'withdrawal_fee' => 'Nostomaksu',
        'transaction_levy' => 'Transaktiovero',
        'foreign_transaction_fee' => 'Valuutanvaihtomaksu',
        'commission' => 'Palkkio',
        'debit_interest' => 'Korkokulut',
        'overdraft' => 'Tilinylitysmaksu',
        'overdraft_interest' => 'Tilinylityskorko',
        'insufficient_funds' => 'Katteettomuusmaksu',
        'penalty_fee' => 'Viivästysmaksu',
        'loan_arrangement_fee' => 'Järjestelypalkkio',
    ],

    'cp_card' => [
        'aria' => 'Vastapuoli: :name',
        'recent_aria' => 'Viimeaikainen toiminta',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Rahoitusketju: ',
        'join' => ' kohteeseen ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN piilotettu — paljasta se napsauttamalla Näytä IBAN',
        // i18n-review: fi · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN piilotettu — paljasta se napauttamalla Näytä IBAN',
        'show' => 'Näytä IBAN',
        'hide' => 'Piilota IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Tietosuojahuomautus henkilöyhteystiedosta',
        'body' => '🔒 Tämä on henkilöyhteystieto. IBAN on piilossa, kunnes paljastat sen, eikä se tule mukaan vienteihin. Nimi näkyy silti kaikkialla, missä sen tapahtumatkin näkyvät.',
    ],

    'self_stub' => [
        'aria' => 'Ei todellinen vastapuoli',
        'heading' => 'Tämä ei oikeastaan ole vastapuoli',

        'body_rest_html' => ' näkyy täällä, koska se esiintyy tapahtumissasi tilien välisenä rahoitusosuutena. Se on kuitenkin <strong>oma tilisi</strong>, ei taho, jonka kanssa asioit.',
        'body2' => 'Avaa tilinäkymä, niin näet saldon, tiliotteet ja koko tapahtumahistorian.',
        'open_cta' => 'Avaa tilin :name näkymä →',
        'hide_cta' => 'Piilota tästä listasta',
        'recent_legs' => 'Viimeisimmät tilien väliset osuudet',
    ],
];
