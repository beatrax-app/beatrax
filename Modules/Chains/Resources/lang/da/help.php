<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Én betaling betaler ofte for flere andre: en kortafregning på bankkontoen dækker en måneds kortkøb, og en hævning fra banken finansierer en wallet-betaling fra dage før. En kæde noterer, hvilken hævning der betalte hvad, så et køb på ét kontoudtog kan spores tilbage til de penge, der rent faktisk forlod kontoen. Beatrax knytter selv de sikre sammen og lader resten ligge i gennemgangskøen til dig. Bekræft samme slags forbindelse et par gange, og den holder op med at spørge om netop den slags.',
];
