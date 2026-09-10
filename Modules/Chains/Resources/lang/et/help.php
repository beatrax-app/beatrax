<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Üks makse maksab sageli mitme teise eest: kaardi arveldus pangakontol katab kuu jagu kaardiostusid ja pangast tehtud väljavõtmine rahastab paar päeva varem tehtud rahakotimakset. Ahel talletab, milline kanne mille eest maksis, nii et ühel väljavõttel olev ost on jälgitav rahani, mis päriselt sinu kontolt lahkus. Beatrax seob kindlad juhtumid ise ja jätab ülejäänud sulle ülevaatusjärjekorda. Kinnita paar korda sama liiki seost ja ta lõpetab selle liigi kohta küsimise.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Vihje on pool sidet: Beatrax leidis makseahela ühe otsa ja teist mitte, nii et pani nähtu kirja, selle asemel et ülejäänut oletada. Enamik laheneb ise — arveldus ootab siin, kuni saabuvad üksikud kulud, mille eest see maksti, ja siis saab sellest päris ahel. Ülejäänud võib rahulikult kõrvale jätta ja sinu kirjetes ei muutu nii ega naa midagi.',
];
