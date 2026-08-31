<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Rahat, jotka ovat jo tulleet tilille eivätkä ole vielä missään kuoressa: tämän jakson tulot, plus edelliseltä jaksolta jakamatta jäänyt osuus, miinus kaikki alla jaettu. Vie luku nollaan, niin mikään ei jää suunnittelematta. Nollan alle mennyt luku tarkoittaa, että olet jakanut enemmän kuin tilille on oikeasti tullut — ota jotain takaisin jostain kuoresta tai odota seuraavaa palkkapäivää.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Mitä tapahtuu kuorelle, joka on kuluttanut enemmän kuin siinä on, kun jakso päättyy. Valinnalla ”:reduce” vajaus vähennetään ensimmäisenä siitä, mitä sinulla on jaettavana seuraavalla jaksolla, ja kuori itse alkaa taas nollasta. Valinnalla ”:carry” vajaus jää sinne missä se syntyi: kuori avautuu miinuksella ja se on täytettävä uudelleen ennen kuin se maksaa mitään, eikä muu suunnitelma liiku.',
];
