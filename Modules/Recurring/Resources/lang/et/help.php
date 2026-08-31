<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Väljavõte on lame kuupäevade ja summade loend ning miski selles ei ütle, millised read on üks ja seesama püsikohustus. Beatrax rühmitab read makse saaja järgi, viskab välja summad, mis rühmast välja jäävad, ja pakub seeriat alles siis, kui ridade vahed paigutuvad ühtlasesse nädalasesse, kuusesse, kvartaalsesse või aastasesse rütmi — kõik ebakorrapärasem jääb üldse pakkumata. Tagasi loeb ta ainult nii kaugele, kui ulatub „:setting“ seadetes, ja see algab lühimast vahemikust, millega ta üldse töötada saab, nii et aastane arve jääb nähtamatuks, kuni sa seda laiendad. Sinu andmetega ei tehta siin midagi enne, kui oled kinnitanud.',
];
