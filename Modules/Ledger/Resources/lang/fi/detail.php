<?php

declare(strict_types=1);

return [
    'page_title' => 'Tapahtuma',
    'heading' => 'Tapahtuma',

    'counterparty' => 'Vastapuoli',
    'amount_native' => 'Summa (alkuperäinen)',
    'amount_settled' => 'Summa (tilitetty EUR)',
    'effective_rate' => 'Toteutunut kurssi',
    'ics_markup' => 'Sisältää mahdollisen ICS-lisän.',

    'split' => [
        'category' => 'Kategoria',
        'open' => 'Jaa kategorioihin',
        'heading' => 'Jaa kategorioiden kesken',
        'total' => 'Yhteensä :amount',
        'tax_per_category' => 'Verotunnisteet asetetaan kategoriakohtaisesti alla.',
        'choose_category' => 'Valitse kategoria',
        'note_label' => 'Muistiinpano',
        'note_placeholder' => 'Muistiinpano (valinnainen)',
        'tax_deductible' => 'Verovähennyskelpoinen',
        'remove_leg_aria' => 'Poista tämä kategoria',
        'add_category' => '+ Lisää kategoria',
        'soft_cap' => ':count / ~20 kategoriaa — harkitse pienten summien yhdistämistä.',
        'remaining_zero' => 'Jäljellä :amount ✓',
        'remaining_to_assign' => 'Jäljellä jaettavaksi: :amount',
        'over_allocated' => 'Jaettu :amount liikaa — pienennä jotakin osaa.',
        'save' => 'Tallenna jako',
        'saving' => 'Tallennetaan…',
        'unsplit' => 'Pura tapahtuman jako',
        'remove_to_one' => 'Tämän poistaminen jättää yhden kategorian: :category.',
        'remove_to_one_fallback' => 'tämä kategoria',
        'remove_category' => 'Poista kategoria',
        'keep_category' => 'Säilytä tämä kategoria',
        'restore_single' => 'Palautetaanko yhdeksi kategoriaksi?',
        'confirm_unsplit' => 'Kyllä, pura jako',
        'keep_split' => 'Säilytä jako',
    ],

    'tax' => [
        'section_aria' => 'Verotunniste',
        'label' => 'Verovähennyskelpoinen',
    ],

    'reclassify' => [
        'heading' => 'Luokittele uudelleen',
        'help' => 'Ohita tunnistettu tyyppi. Jos tämä tapahtuma on parina toisen kanssa, muun kuin siirtotyypin valinta purkaa parin molemmilta puolilta.',
        'choose_aria' => 'Valitse uusi tapahtumatyyppi',
        'choose_option' => 'Valitse tyyppi…',
        'save' => 'Tallenna',
    ],

    'type_label' => [
        'expense' => 'Meno',
        'income' => 'Tulo',
        'transfer_out' => 'Lähtevä siirto',
        'transfer_in' => 'Saapuva siirto',
        'fee' => 'Palvelumaksu',
        'refund' => 'Hyvitys',
        'adjustment' => 'Oikaisu',
    ],

    'note' => [
        'heading' => 'Muistiinpano',
        'help' => 'Henkilökohtainen muistiinpano tälle tapahtumalle. Näkyy vain sinulle.',
        'label' => 'Muistiinpano',
        'placeholder' => 'Lisää muistiinpano…',
        'save' => 'Tallenna muistiinpano',
        'saved' => 'Tallennettu',
    ],

    'reassign' => [
        'heading' => 'Vaihda vastapuoli',
        'help' => 'Ohita tälle tapahtumalle tunnistettu vastapuoli.',
        'choose_aria' => 'Valitse vastapuoli',
        'choose_option' => 'Valitse vastapuoli…',
        'submit' => 'Vaihda',
    ],

    'goal' => [
        'heading' => 'Säästötavoite',
        'help' => 'Laske tämä tapahtuma yhteen säästötavoitteistasi.',
        'choose_aria' => 'Valitse säästötavoite',
        'choose_option' => 'Valitse tavoite…',
        'submit' => 'Lisää tavoitteeseen',
        'remove_aria' => 'Poista :name',
    ],

    'delete' => [
        'heading' => 'Poista tapahtuma',
        'help' => 'Poistaa tämän tapahtuman pysyvästi. Toimintoa ei voi peruuttaa.',
        'button' => 'Poista',
        'confirm_prompt' => 'Oletko varma?',
        'confirm' => 'Kyllä, poista',
        'cancel' => 'Peruuta',
    ],

    'chain' => [
        'view' => 'Näytä ketju',
    ],

    'toast' => [
        'reconciled_locked' => 'Tämä tapahtuma on täsmäytetty. Pura täsmäytys, niin voit tehdä muutoksia.',
        'reclassified_pair_removed' => 'Luokiteltu uudelleen tyypiksi :type — pari purettu',
        'reclassified' => 'Luokiteltu uudelleen tyypiksi :type',
        'note_saved' => 'Muistiinpano tallennettu',
        'unreconciled' => 'Täsmäytys purettu — voit muokata tätä tapahtumaa taas.',
        'counterparty_updated' => 'Vastapuoli päivitetty',
        'goal_attributed' => 'Lasketaan tähän tavoitteeseen',
        'goal_attribution_removed' => 'Ei enää lasketa tähän tavoitteeseen',
        'split_saved' => 'Jako tallennettu',
        'removed_one_remains' => 'Poistettu — yksi kategoria jäljellä',
        'unsplit_restored' => 'Jako purettu — palautettu yhdeksi kategoriaksi',
    ],

    'errors' => [
        'totals_must_match' => 'Tallennus epäonnistui — osien summan on vastattava tapahtuman summaa täsmälleen.',
        'not_found' => 'Tapahtumaa ei löytynyt.',
        'amount_zero' => 'Summa ei voi olla 0,00 €',
        'choose_category' => 'Valitse kategoria.',
        'choose_before_removing' => 'Valitse kategoria ennen poistamista.',
        'choose_before_unsplitting' => 'Valitse kategoria ennen jaon purkamista.',
        'not_found_or_unowned' => 'Tapahtumaa ei löytynyt tai se ei kuulu käyttäjälle.',
        'reconciled_split' => 'Tämä tapahtuma on täsmäytetty. Pura täsmäytys, niin voit muuttaa sen jakoa.',
        'not_splittable' => "Tapahtumatyyppiä ':type' ei voi jakaa.",
        'min_two_legs' => 'Jako vaatii vähintään 2 osaa.',
        'legs_non_zero' => 'Osien summat eivät voi olla nollia.',
        'legs_parent_sign' => 'Osien summilla on oltava sama etumerkki kuin päätapahtumalla.',
        'leg_category_not_accessible' => 'Osan kategoriaa ei löytynyt tai käyttäjällä ei ole siihen pääsyä.',
        'survivor_not_accessible' => 'Jäljelle jäävää kategoriaa ei löytynyt tai käyttäjällä ei ole siihen pääsyä.',
        'survivor_must_be_current' => 'Jäljelle jäävän kategorian on oltava yksi jaon nykyisistä osakategorioista.',
    ],
];
