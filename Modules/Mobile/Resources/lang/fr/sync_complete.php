<?php

declare(strict_types=1);

return [
    'page_title' => 'Cet appareil est synchronisé',
    'heading' => 'Cet appareil est synchronisé',
    'records' => ':count enregistrement copié depuis :peer.|:count enregistrements copiés depuis :peer.',
    'records_none' => 'À jour avec :peer. Il n\'y avait rien de nouveau à copier.',
    'withheld' => ':count changement n\'est pas encore arrivé.|:count changements ne sont pas encore arrivés.',
    'withheld_action' => 'Signés par un appareil que celui-ci ne peut pas vérifier. Rien n\'est perdu — tout reste sur :peer et arrivera si l\'un de tes appareils transmet cette identité et que tu la confirmes dans :section.',
    'how_it_works' => 'À partir de maintenant',
    'automatic_title' => 'C’est toi qui choisis quand ça se synchronise',
    'automatic_body' => 'Tout ce que tu modifies sur l\'un des appareils apparaît sur l\'autre la prochaine fois que tu appuies sur :action. Ça ne peut pas tourner en arrière-plan — le verrou de l\'app détient la seule clé.',
    'lan_title' => 'Sur le même réseau',
    'lan_body' => 'Quand les deux appareils sont sur ton réseau domestique, ils se parlent directement, sans rien entre les deux.',
    'relay_title' => 'Quand tu es dehors',
    'relay_body' => 'Les changements attendent, chiffrés, sur ton relais jusqu\'à ce que l\'autre appareil revienne en ligne. Cet appareil les récupère la prochaine fois que tu appuies sur :action.',
    'no_relay_title' => 'Quand tu es dehors',
    'no_relay_body' => 'Les changements attendent sur cet appareil jusqu\'à ce que les deux soient ensemble sur ton réseau domestique et que tu appuies ici sur :action.',
    'encrypted_title' => 'Seuls tes appareils peuvent le lire',
    'encrypted_body' => 'Tout est chiffré avant de quitter un appareil, et seuls tes appareils appairés détiennent les clés.',
    'continue' => 'Commencer à utiliser Beatrax',
    'peer_fallback' => 'ton autre appareil',
];
