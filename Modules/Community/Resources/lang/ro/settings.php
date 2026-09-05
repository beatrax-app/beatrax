<?php

declare(strict_types=1);

return [
    'about_body' => 'Un fișier YAML livrat cu aplicația, care mapează codurile criptice din extrasele bancare la nume prietenoase de comercianți. Când este activată, Beatrax citește lista la import; trimiterea unei sugestii deschide GitHub în browser.',

    'mappings' => ':count mapare|:count mapări|:count de mapări',
    'contributors' => ':count contribuitor|:count contribuitori|:count de contribuitori',

    'use_shared_list' => [
        'title' => 'Folosește lista comună de comercianți',
        'help' => 'Lasă Beatrax să citească lista livrată cu aplicația pentru a completa nume prietenoase la comercianții pe care nu i-ai redenumit tu.',
    ],

    'offer_to_contribute' => [
        'title' => 'Oferă-te să contribui',
        'help' => 'Afișează butonul „Ajută-i pe alții să identifice asta” pe rândul de triaj, ca să poți trimite o sugestie în lista comună dintr-un singur clic.',
        // i18n-review: ro · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Afișează butonul „Ajută-i pe alții să identifice asta” pe rândul de triaj, ca să poți trimite o sugestie în lista comună dintr-o singură atingere.',
    ],

    'update_on_updates' => [
        'title' => 'Actualizează lista comună la actualizările aplicației',
        'help' => 'Reîmprospătează lista livrată cu aplicația de fiecare dată când Beatrax se actualizează.',
        'help_phone' => 'Reîmprospătează lista livrată cu aplicația de fiecare dată când se instalează o versiune nouă de Beatrax din App Store sau Google Play.',
        'note' => 'Se activează la o actualizare viitoare a aplicației — versiunea pe care o folosești este afișată în partea de sus a barei laterale.',
    ],
];
