<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Egy fizetés gyakran több másikat fizet ki: a kártya elszámolása a bankszámlán egy hónapnyi kártyás vásárlást fedez, egy banki kivét pedig egy néhány nappal korábbi tárcás fizetést finanszíroz. A lánc rögzíti, melyik terhelés mit fizetett ki, így az egyik kivonaton szereplő vásárlás visszakövethető addig a pénzig, amely valóban elhagyta a számládat. A Beatrax a biztos eseteket magától összeköti, a többit pedig neked hagyja az ellenőrzési sorban. Erősítsd meg néhányszor ugyanazt a fajta kapcsolatot, és többé nem kérdez rá arra a fajtára.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'A tipp egy fél kapcsolat: a Beatrax megtalálta egy fizetési lánc egyik oldalát, a másikat nem, ezért leírta, amit látott, ahelyett hogy a többit kitalálná. A legtöbb magától rendeződik — egy elszámolás itt vár, amíg meg nem érkeznek az egyes terhelések, amelyeket kifizetett, és akkor igazi lánc lesz belőle. A többit nyugodtan elvetheted, a könyvelésedben így is, úgy is semmi nem változik.',
];
