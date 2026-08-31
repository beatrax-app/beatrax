<?php

declare(strict_types=1);

return [
    'page_title' => 'Hinnanmuutoshälytykset',
    'intro_anomaly' => 'Yksittäiset veloitukset, jotka näyttävät sinulle epätavallisilta.',
    'intro_drift' => 'Hyväksytyt toistuvat sarjat, joiden viimeisin veloitus ylitti asettamasi rajan.',
    'adjust_threshold' => 'Säädä rajaa →',
    'adjust_sensitivity' => 'Säädä herkkyyttä →',

    'type_aria' => 'Hälytyksen tyyppi',
    'type' => [
        'drift' => 'Tilausten hinnanmuutos',
        'anomaly' => 'Poikkeavat veloitukset',
    ],

    'lifecycle_aria' => 'Hälytyksen elinkaari',
    'tabs' => [
        'open' => 'Avoimet',
        'history' => 'Historia',
        'dismissed' => 'Ohitetut',
    ],

    'load_more' => 'Lataa lisää',
    'group_count' => ':count hinnanmuutos avoinna|:count hinnanmuutosta avoinna',

    'anomaly_empty' => [
        'open_heading' => 'Ei poikkeavia veloituksia',
        'open_body' => 'Beatrax seuraa kulutustasi ja merkitsee veloitukset, jotka näyttävät epätavallisilta. Kun jotain poikkeavaa ilmenee, se näkyy täällä.',
        'history_heading' => 'Ei vielä kuitattuja veloituksia',
        'history_body' => 'Kuittaamasi veloitukset näkyvät täällä, jotta näet, mitä olet jo käynyt läpi.',
        'dismissed_heading' => 'Mitään ei ole vielä ohitettu',
        'dismissed_body' => 'Kun merkitset veloituksen odotetuksi, se päätyy tänne vaimennussääntönsä kanssa.',
    ],

    'empty_open' => [
        'heading' => 'Ei avoimia hinnanmuutoshälytyksiä',
        'body' => 'Beatrax seuraa hyväksyttyjä toistuvia sarjojasi ja merkitsee ne, joiden viimeisin veloitus poikkeaa edellisestä summasta enemmän kuin rajasi sallii. Säädä rajaa kohdassa',
        'link' => 'Asetukset → Hinnanmuutoshälytyksen oletusraja',
    ],
    'empty_history' => [
        'heading' => 'Ei vielä kuitattuja hinnanmuutoksia',
        'body' => 'Kuitatut hinnanmuutoshälytykset näkyvät täällä, jotta näet, mitä olet jo käynyt läpi.',
    ],
    'empty_dismissed' => [
        'heading' => 'Mitään ei ole vielä ohitettu',
        'body' => 'Kun kerrot Beatraxille irtisanoneesi sarjan, päätös päätyy tänne aikaleiman kanssa.',
    ],

    'row' => [
        'per_year' => '/v',
        'meta_prior_now' => 'ennen :prior → nyt :now',
        'meta_detected' => 'havaittu :date',
        'meta_threshold' => 'raja ±:percent %',
        'meta_eur_equiv' => '(≈ :amount/v)',
        'cancel_impact' => 'Irtisano tämä → säästä :amount/v',
        'cadence_flipped' => 'Maksuväli vaihtui — näkyy myös kohdassa',
        'cadence_flipped_link' => 'Tarkista toistuvat',
        'acknowledge' => 'Kuittaa',
        'acknowledge_aria' => 'Kuittaa hinnanmuutoshälytys :id',
        'snooze' => 'Torkuta ▾',
        'snooze_1w' => '1 viikko',
        'snooze_1m' => '1 kuukausi',
        'snooze_3m' => '3 kuukautta',
        'model_cancel' => 'Mallinna irtisanominen ↗',
        'model_cancel_aria' => 'Mallinna irtisanominen — mallintaa irtisanomisen ennusteessa hinnanmuutoshälytykselle :id',
        'cancelled' => 'Irtisanoin tämän',
        'cancelled_aria' => 'Irtisanoin tämän — ohittaa hinnanmuutoshälytyksen :id irtisanottuna',
    ],

    'toasts' => [
        'gone' => 'Tätä ilmoitusta ei enää ole.',
        'acknowledged' => 'Kuitattu',
        'snoozed' => 'Torkutettu',
        'dismissed' => 'Ohitettu',
        'suppression_added' => 'Vaimennussääntö lisätty — Kumoa',
        'dismissed_expected' => 'Ohitettu odotettuna',
        'reopened' => 'Avattu uudelleen',
        'dismissed_cancelled' => 'Ohitettu irtisanottuna',
    ],
];
