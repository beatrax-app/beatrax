<?php

declare(strict_types=1);

return [
    'title' => 'Tarkista toistuvat',
    'subtitle' => 'Hyväksy, torkuta tai hylkää tunnistetut toistuvat ehdotukset.',

    'tabs' => [
        'pending' => 'Odottaa',
        'rejected' => 'Hylätyt',
        'cadence_changed' => 'Maksuväli muuttui',
    ],

    'bulk' => [
        'aria' => 'Joukkotoiminnot',
        'selected' => ':count valittu',
        'approve' => 'Hyväksy :count',
        'reject' => 'Hylkää :count',
    ],

    'empty' => [
        'heading' => 'Ei mitään tarkistettavaa',
        'pending' => 'Toistuvat ehdotukset ilmestyvät tähän, kun tunnistin löytää vakaita kuukausittaisia ryppäitä.',
        'rejected' => 'Hylätyt ehdotukset näkyvät tässä, jotta voit palauttaa ne, jos muutat mielesi.',
        'cadence_changed' => 'Hyväksytyt sarjat, joiden maksuväli on vaihtunut, tulevat tänne uudelleen tarkistettaviksi.',
    ],

    'next' => 'Seuraava',
    'cadence_changed_note' => 'maksuväli muuttui',

    'select_aria' => 'Valitse toistuva sarja :id',
    'un_reject' => 'Peru hylkäys',
    'approve' => 'Hyväksy',
    'approve_aria' => 'Hyväksy toistuva sarja :id',
    'reject' => 'Hylkää',
    'reject_aria' => 'Hylkää toistuva sarja :id',
    'snooze' => 'Torkuta',
    'snooze_aria' => 'Torkuta toistuvaa sarjaa :id',
    'snooze_1w' => '1 viikko',
    'snooze_1m' => '1 kuukausi',
    'snooze_3m' => '3 kuukautta',
    'edit_name' => 'Muokkaa nimeä',
    'edit_name_aria' => 'Nimeä toistuva sarja :id uudelleen',
    'new_name_label' => 'Uusi nimi tälle sarjalle',
    'save' => 'Tallenna',

    'toast' => [
        'approved' => 'Hyväksytty',
        'rejected' => 'Hylätty',
        'snoozed' => 'Torkutettu',
        'renamed' => 'Nimetty uudelleen',
        'un_rejected' => 'Hylkäys peruttu',
        'bulk_approved' => ':count hyväksytty',
        'bulk_rejected' => ':count hylätty',
    ],
];
