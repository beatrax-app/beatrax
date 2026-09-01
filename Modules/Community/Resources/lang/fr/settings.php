<?php

declare(strict_types=1);

return [
    'about_body' => 'Un fichier YAML intégré qui associe les codes cryptiques des relevés bancaires à des noms de commerçants lisibles. Activer l\'option autorise Beatrax à lire la liste à l\'import ; envoyer une suggestion ouvre GitHub dans ton navigateur.',

    'mappings' => ':count correspondance|:count correspondances',
    'contributors' => ':count contributeur|:count contributeurs',

    'use_shared_list' => [
        'title' => 'Utiliser la liste partagée des commerçants',
        'help' => 'Autorise Beatrax à lire la liste intégrée pour compléter les noms lisibles des commerçants que tu n\'as pas renommés toi-même.',
    ],

    'offer_to_contribute' => [
        'title' => 'Proposer de contribuer',
        'help' => 'Affiche le bouton « Aide les autres à l\'identifier » sur la ligne de tri, pour envoyer une suggestion à la liste partagée en un clic.',
        // i18n-review: fr · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Affiche le bouton « Aide les autres à l\'identifier » sur la ligne de tri, pour envoyer une suggestion à la liste partagée en un appui.',
    ],

    'update_on_updates' => [
        'title' => 'Mettre à jour la liste partagée lors des mises à jour de l\'app',
        'help' => 'Actualise la liste intégrée chaque fois que Beatrax se met à jour.',
        'help_phone' => 'Actualise la liste intégrée chaque fois qu\'une nouvelle version de Beatrax est installée depuis l\'App Store ou Google Play.',
        'note' => 'S\'active avec une future mise à jour de l\'app — voir Paramètres → À propos pour la version actuelle.',
    ],
];
