<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Säästöpotti panee rahaa sivuun tilin sisällä sen sijaan että siirtäisi sen pois, joten pankin saldo ei muutu, kun lisäät rahaa tai nostat sitä. Jokainen tili näyttää kolme lukua: ”:real” on se, mitä pankilla on, ”:allocated” on se, minkä pottisi ovat yhdessä varanneet, ja ”:unallocated” on se, mitä jää yli — ainoa osa, joka on vielä vapaasti käytettävissä. Kun viimeinen luku painuu nollan alle, potit varaavat enemmän kuin tilillä on, ja jokainen potin saldo on liian suuri, kunnes otat jotain takaisin.',
];
