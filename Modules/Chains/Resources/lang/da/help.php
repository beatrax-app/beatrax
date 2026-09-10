<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Én betaling betaler ofte for flere andre: en kortafregning på bankkontoen dækker en måneds kortkøb, og en hævning fra banken finansierer en wallet-betaling fra dage før. En kæde noterer, hvilken hævning der betalte hvad, så et køb på ét kontoudtog kan spores tilbage til de penge, der rent faktisk forlod kontoen. Beatrax knytter selv de sikre sammen og lader resten ligge i gennemgangskøen til dig. Bekræft samme slags forbindelse et par gange, og den holder op med at spørge om netop den slags.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Et hint er en halv kobling: Beatrax fandt den ene side af en betalingskæde og ikke den anden og skrev derfor ned, hvad den så, i stedet for at gætte resten. De fleste løser sig selv — en afregning venter her, indtil de enkelte posteringer, den betalte for, er importeret, og bliver så til en rigtig kæde. Resten kan du trygt afvise, og i dit regnskab ændrer der sig ikke noget under nogen omstændigheder.',
];
