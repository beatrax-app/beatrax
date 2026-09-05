<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Peržiūra ir įrašymas',
    'h1' => 'Peržiūrėk viską, ką radome',

    'lede_counts' => ':transactions iš :sources.',
    'source' => ':count šaltinio|:count šaltinių|:count šaltinių',
    'lede_confirm' => 'Patvirtink pradinius likučius ir įrašyk.',

    'empty' => 'Kol kas nėra ko peržiūrėti. Ankstesniuose žingsniuose įkelk išrašą, kad čia matytum savo operacijas.',

    'sb_eyebrow_label' => '🧮 PRADINIAI LIKUČIAI ·',
    'account_detected' => ':count APTIKTA SĄSKAITA|:count APTIKTOS SĄSKAITOS|:count APTIKTA SĄSKAITŲ',
    'sb_lede' => 'Nustatėme kiekvienos sąskaitos pradinį likutį. Prieš įrašydamas patvirtink arba pataisyk.',

    'txn' => ':count operacija|:count operacijos|:count operacijų',
    'to_commit' => 'bus įrašyta ·',
    'already_imported' => ':count jau importuota|:count jau importuota|:count jau importuota',
    'commit_committing' => 'Įrašoma…',
    'commit_count' => 'Įrašyti viską (:count operacija) →|Įrašyti viską (:count operacijos) →|Įrašyti viską (:count operacijų) →',
    'commit_empty' => 'Įrašyti viską (—) →',
    'skip' => 'Kol kas praleisti',

    'errors' => [
        'nothing_to_commit' => 'Nėra ko įrašyti.',
        'commit_failed' => 'Nepavyko įrašyti tavo išrašų. Niekas nepakeista — bandyk dar kartą.',
    ],

    'section' => [
        'from_prefix' => 'IŠ ',
        'from_bank' => 'IŠ TAVO BANKO IŠRAŠO',
        'from_ics' => 'IŠ TAVO ICS KORTELĖS IŠRAŠŲ',
        'from_paypal' => 'IŠ PAYPAL',
        'row' => ':count EILUTĖ|:count EILUTĖS|:count EILUČIŲ',
        'badge_ready' => '✓ PARUOŠTA',
        'badge_empty' => 'TUŠČIA',
        'badge_error' => 'REIKIA ĮKELTI IŠ NAUJO',
        'error_body' => 'Nepavyko perskaityti visų šio šaltinio failų. Pabandyk kitą failą →',
        'left_out' => 'Vienas failas čia buvo praleistas, todėl bus išsaugota tik kita dalis: :reason|:count failai čia buvo praleisti, todėl bus išsaugota tik kita dalis: :reason|:count failų čia buvo praleista, todėl bus išsaugota tik kita dalis: :reason',
        'rows_skipped' => 'Kai kurių čia esančių eilučių nepavyko perskaityti ir jos bus praleistos: :reason',
        'empty_body' => 'Šis išrašas tuščias.',
        'col_date' => 'Data',
        'col_type' => 'Tipas',
        'col_counterparty' => 'Kita šalis',
        'col_amount' => 'Suma',
        'load_more' => 'Įkelti daugiau (liko :remaining)',
        'rows_shown' => 'rodoma :count eilutė|rodomos :count eilutės|rodoma :count eilučių',
    ],
];
