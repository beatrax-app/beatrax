<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Yksi maksu maksaa usein monta muuta: korttitilitys pankkitilillä kattaa kuukauden korttiostoja, ja pankista tehty nosto rahoittaa päiviä aiemmin tehdyn lompakkomaksun. Ketju tallentaa, mikä veloitus maksoi minkäkin, jotta yhdellä tiliotteella näkyvä ostos voidaan jäljittää siihen rahaan, joka oikeasti lähti tililtäsi. Beatrax yhdistää varmat tapaukset itse ja jättää loput tarkistusjonoon sinulle. Vahvista samanlainen yhteys muutaman kerran, niin se lakkaa kysymästä sen tyyppisistä.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Vihje on puolikas linkki: Beatrax löysi maksuketjun toisen pään muttei toista, ja kirjasi näkemänsä sen sijaan että olisi arvannut loput. Useimmat selviävät itsestään — suoritus odottaa täällä, kunnes sen maksamat yksittäiset veloitukset tuodaan sisään, ja siitä tulee silloin oikea ketju. Loput voi huoletta hylätä, eikä kirjanpitoosi tule kummassakaan tapauksessa muutosta.',
];
