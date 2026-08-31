<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Tiliote on litteä lista päiviä ja summia, eikä mikään siinä kerro, mitkä rivit ovat samaa toistuvaa sitoumusta. Beatrax ryhmittelee rivit maksunsaajan mukaan, hylkää summat, jotka poikkeavat ryhmästä, ja ehdottaa sarjaa vasta kun rivien välit asettuvat tasaiseen viikoittaiseen, kuukausittaiseen, neljännesvuosittaiseen tai vuosittaiseen rytmiin — kaikkea epäsäännöllisempää ei ehdoteta lainkaan. Se lukee taaksepäin vain niin pitkälle kuin ”:setting” asetuksissa sallii, ja se alkaa lyhimmästä jaksosta, jolla se ylipäätään pystyy toimimaan, joten vuosittainen lasku pysyy näkymättömissä kunnes laajennat sitä. Mitään ei sovelleta tietoihisi ennen kuin hyväksyt sen.',
];
