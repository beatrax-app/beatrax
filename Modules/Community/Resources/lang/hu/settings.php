<?php

declare(strict_types=1);

return [
    'about_body' => 'Egy a Beatraxszal együtt szállított YAML-fájl, amely a rejtélyes banki kivonatkódokat beszédes kereskedőnevekhez rendeli. Bekapcsolva a Beatrax importáláskor olvassa a listát; a javaslat beküldése megnyitja a GitHubot a böngésződben.',

    'mappings' => ':count megfeleltetés|:count megfeleltetés',
    'contributors' => ':count közreműködő|:count közreműködő',

    'use_shared_list' => [
        'title' => 'A megosztott kereskedőlista használata',
        'help' => 'Engedd, hogy a Beatrax a beépített listából töltse ki a beszédes neveket azoknál a kereskedőknél, amelyeket még nem neveztél át.',
    ],

    'offer_to_contribute' => [
        'title' => 'Hozzájárulás felajánlása',
        'help' => 'Jelenítse meg a „Segíts másoknak azonosítani” gombot a besorolási soron, hogy egy kattintással beküldhesd a javaslatot a megosztott listára.',
        // i18n-review: hu · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Jelenítse meg a „Segíts másoknak azonosítani” gombot a besorolási soron, hogy egy koppintással beküldhesd a javaslatot a megosztott listára.',
    ],

    'update_on_updates' => [
        'title' => 'A megosztott lista frissítése az alkalmazás frissítésekor',
        'help' => 'Frissítse a beépített listát minden alkalommal, amikor a Beatrax frissíti magát.',
        'help_phone' => 'Frissítse a beépített listát minden alkalommal, amikor a Beatrax új verziója az App Store-ból vagy a Google Play-ből települ.',
        'note' => 'Egy későbbi alkalmazásfrissítéssel lép életbe — az aktuális verziót lásd: Beállítások → Névjegy.',
    ],
];
