<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count muutos on tehty Beatraxin uudemmalla versiolla|:count muutosta on tehty Beatraxin uudemmalla versiolla',
        'body' => 'Se, mikä hylättiin, viittaa johonkin, mitä tässä Beatraxin versiossa ei ole, joten tällä laitteella ei ollut sille paikkaa. Se on yhä sillä laitteella, joka sen teki, eikä mitään sinun tiedoistasi ole poistettu.',
        'action' => 'Päivitä Beatrax tällä laitteella. Päivityksen jälkeen tehdyt muutokset saapuvat normaalisti, mutta mitään jo hylättyä ei lähetetä uudelleen — tee muutos täällä uudestaan, jos tarvitset sitä myös tällä laitteella.',
    ],
    'untrusted_author' => [
        'summary' => ':count muutoksen on allekirjoittanut laite, jota tämä laite ei tunne|:count muutosta on allekirjoittanut laite, jota tämä laite ei tunne',
        'body' => 'Se, mikä hylättiin, tuli laitteelta, jota ei ole koskaan paritettu tämän kanssa, tai laitteelta, jonka poistit. Tänne ei kirjoitettu mitään, eikä mitään täällä jo ollutta muutettu.',
        'action' => 'Jos poistit laitteen itse, juuri näin poistaminen toimii eikä korjattavaa ole. Jos et poistanut, tarkista tämän sivun laiteluettelo.',
    ],
    'not_verified' => [
        'summary' => ':count muutos ei läpäissyt tämän laitteen turvatarkistusta|:count muutosta ei läpäissyt tämän laitteen turvatarkistusta',
        'body' => 'Allekirjoitus ei vastannut laitetta, jonka väitettiin tehneen muutoksen, tai muutos oli osoitettu toiselle tilille. Tänne ei kirjoitettu mitään. Omien laitteidesi välillä näin ei pitäisi käydä.',
        'action' => 'Tarkista tämän sivun laiteluettelo ja poista kaikki, mitä et tunnista. Jos jokainen siellä oleva laite on sinun ja tämä toistuu, kyse on Beatraxin viasta eikä sellaisesta, jonka voisit täältä korjata.',
    ],
    'diverged' => [
        'summary' => ':count muutos toiselta laitteelta jäi tänne tallentamatta|:count muutosta toiselta laitteelta jäi tänne tallentamatta',
        'body' => 'Tänne saapui jotain, mitä tämä laite ei voinut tallentaa: tietue, josta puuttuu osa itseään, päivämäärä, jota ei ole olemassa, jako, joka ei enää täsmää, tietue, jolle kaksi laitetta oli jo antanut saman identiteetin, tai poisto sellaiselle, joka on täällä yhä käytössä. Se, mikä hylättiin, on toisella laitteellasi eivätkä tällä, joten laitteilla ei enää ole sama sisältö.',
        'action' => 'Vertaa toisen laitteesi tietuetta siihen, mitä näet täällä, ja tee muutos täällä uudelleen — tai poista se täällä uudestaan, jos jokin muualla poistamasi on täällä yhä. Hylättyä ei lähetetä uudelleen itsestään.',
    ],
    'last_seen' => 'Viimeisin: :when',
];
