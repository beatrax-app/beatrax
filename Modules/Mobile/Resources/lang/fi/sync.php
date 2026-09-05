<?php

declare(strict_types=1);

return [
    'page_title' => 'Tiedot ja laitteet',
    'heading' => 'Tiedot ja laitteet',
    'sync_status' => 'Synkronoinnin tila',
    'syncing_progress' => 'Synkronoidaan… :count tietue|Synkronoidaan… :count tietuetta',
    'initial_sync_aria' => 'Ensimmäisen synkronoinnin edistyminen',
    'no_peers' => 'Muodosta laitepari toisen laitteen kanssa, niin synkronointi alkaa.',
    'sync_now' => 'Synkronoi nyt',
    'result' => [
        'synced' => 'Synkronoitu toisen laitteesi kanssa.',
        'unreachable' => 'Toista laitetta ei tavoitettu — tarkista, että molemmat ovat samassa verkossa.',
        'locked' => 'Avaa sovelluksen lukitus synkronointia varten.',
        'not_enabled' => 'Synkronointia ei ole vielä otettu käyttöön tällä laitteella.',
        'unreadable' => 'Tämän laitteen avain ei aukea enää. Pariliitä uudelleen jatkaaksesi synkronointia.',
        'paused_on_cellular' => 'Keskeytetty — synkronointi on rajattu Wi-Fi-verkkoon ja käytät mobiilidataa.',
    ],
    'background_note' => 'Beatrax kuuntelee koko sen ajan, kun se on auki, joten paritettu laite voi synkronoida tämän kanssa milloin tahansa. Synkronoi nyt aloittaa tiedonvaihdon tältä puolelta.',
    'background_note_phone' => 'Synkronointi tapahtuu, kun napautat Synkronoi nyt. Taustalla se ei voi toimia — sovelluslukko pitää ainoaa avainta.',
    'network' => 'Verkko',
    'pause_cellular' => 'Keskeytä synkronointi mobiiliverkossa',
    'pause_cellular_help' => 'Oletuksena pois päältä — synkronointi toimii kaikkialla. Ota käyttöön, niin synkronointi tapahtuu vain Wi-Fi-verkossa.',
];
