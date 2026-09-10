<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Eén betaling betaalt vaak voor meerdere andere: een creditcardafrekening op je bankrekening dekt een maand aan kaartaankopen, en een opname van de bank financiert een walletbetaling van dagen eerder. Een keten legt vast welke afschrijving waarvoor betaalde, zodat een aankoop op het ene afschrift terug te volgen is naar het geld dat je rekening echt heeft verlaten. Beatrax legt de zekere verbanden zelf en laat de rest in de beoordelingswachtrij voor jou staan. Bevestig hetzelfde soort verband een paar keer en het vraagt er niet meer naar.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Een hint is een halve koppeling: Beatrax vond één kant van een betaalketen en de andere niet, en heeft opgeschreven wat het zag in plaats van de rest te raden. De meeste lossen zichzelf op — een afwikkeling wacht hier tot de losse afschrijvingen die ermee betaald zijn binnenkomen, en dan wordt het een echte keten. De rest kun je gerust afwijzen; er verandert hoe dan ook niets in je boekhouding.',
];
