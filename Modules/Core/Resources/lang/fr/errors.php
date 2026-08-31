<?php

declare(strict_types=1);

return [
    'back' => 'Retour à Beatrax',

    'not_saved' => 'Rien n’a été enregistré. Tes données sont inchangées — réessaie.',

    'no_longer_here' => 'Cela n’existe plus.',

    '404' => [
        'title' => 'Cette page n\'existe pas',
        'body' => 'Le lien est peut-être ancien, ou la page a été renommée. Tes données n\'ont rien.',
    ],
    '4xx' => [
        'title' => 'Cette requête ne peut pas être traitée',
        'body' => 'La page a été ouverte d’une manière qu’elle n’attend pas. Tes données sont inchangées.',
    ],

    '419' => [
        'title' => 'Ta session a expiré',
        'body' => 'Tu es resté absent assez longtemps pour que la page ne soit plus valable. Rouvre Beatrax et continue.',
    ],

    '500' => [
        'title' => 'Quelque chose s\'est mal passé',
        'body' => 'Le problème a été consigné dans le journal de cet appareil. Tes données n\'ont pas été modifiées.',
    ],

    '503' => [
        'title' => 'Beatrax est brièvement indisponible',
        'body' => 'Une mise à jour ou une maintenance se termine. Réessaie dans un instant.',
    ],
];
