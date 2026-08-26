<?php

declare(strict_types=1);

return [
    'title' => 'Pregled ponavljajočih se',
    'subtitle' => 'Odobri, odloži ali zavrni zaznane predloge ponavljajočih se plačil.',

    'tabs' => [
        'pending' => 'V čakanju',
        'rejected' => 'Zavrnjeno',
        'cadence_changed' => 'Spremenjena pogostost',
    ],

    'bulk' => [
        'aria' => 'Množična dejanja',
        'selected' => 'izbrano: :count',
        'approve' => 'Odobri (:count)',
        'reject' => 'Zavrni (:count)',
    ],

    'empty' => [
        'heading' => 'Ni ničesar za pregled',
        'pending' => 'Predlogi ponavljajočih se plačil pridejo sem, ko detektor zazna stabilne mesečne skupine.',
        'rejected' => 'Zavrnjeni predlogi ostanejo tu, da jih lahko vrneš, če si premisliš.',
        'cadence_changed' => 'Odobrene serije, ki se jim je pogostost spremenila, se pojavijo tu za ponovni pregled.',
    ],

    'next' => 'Naslednje',
    'overdue' => 'Zamuja',
    'cadence_changed_note' => 'pogostost spremenjena',
    'un_reject' => 'Prekliči zavrnitev',
    'approve' => 'Odobri',
    'approve_aria' => 'Odobri ponavljajočo se serijo :id',
    'reject' => 'Zavrni',
    'reject_aria' => 'Zavrni ponavljajočo se serijo :id',
    'snooze' => 'Odloži',
    'snooze_aria' => 'Odloži ponavljajočo se serijo :id',
    'snooze_1w' => '1 teden',
    'snooze_1m' => '1 mesec',
    'snooze_3m' => '3 meseci',
    'edit_name' => 'Uredi ime',
    'edit_name_aria' => 'Preimenuj ponavljajočo se serijo :id',
    'new_name_label' => 'Novo ime za to serijo',
    'save' => 'Shrani',

    'toast' => [
        'approved' => 'Odobreno',
        'rejected' => 'Zavrnjeno',
        'snoozed' => 'Odloženo',
        'renamed' => 'Preimenovano',
        'un_rejected' => 'Zavrnitev preklicana',
        'bulk_approved' => 'Odobreno: :count',
        'bulk_rejected' => 'Zavrnjeno: :count',
    ],
];
