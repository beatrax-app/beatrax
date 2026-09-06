<?php

declare(strict_types=1);

return [
    'heading' => 'Paku seost',
    'intro' => 'Avab brauseris GitHubi, kus ettepanek on juba täidetud. Kaasa lähevad ainult ülal olev muster, nimi, kategooria ja piirkond — ja muster on kirjeldus täpselt nii, nagu su väljavõte selle kirjutas. Sinu nimi ja e-posti aadress ei lahku sellest seadmest.',

    'pattern' => 'Muster',
    'name' => 'Arusaadav nimi',
    'name_placeholder' => 'nt Albert Heijn',
    'category' => 'Kategooria (valikuline)',
    'category_placeholder' => 'nt Toidukaubad',
    'region' => 'Piirkond',

    'regions' => [
        'other' => 'Muu',
    ],

    'yaml_preview' => 'YAML-i eelvaade',

    'cancel' => 'Tühista',
    'submit' => 'Ava GitHubis',

    'toast' => 'Ettepanek avanes sinu brauseris.',

    'errors' => [
        'pattern_required' => 'Muster on kohustuslik.',
        'name_required' => 'Nimi on kohustuslik.',
        'browser_refused' => 'Sinu brauserit ei õnnestunud avada, seega midagi ei saadetud ega lahkunud sellest seadmest. Proovi uuesti või kopeeri ülal olev YAML-eelvaade ise pull requesti.',
    ],
];
