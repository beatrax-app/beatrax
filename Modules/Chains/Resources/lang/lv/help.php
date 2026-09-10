<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Viens maksājums bieži samaksā par vairākiem citiem: kartes norēķins bankas kontā sedz mēnesi ar karti veiktu pirkumu, un izņēmums no bankas finansē maka maksājumu, kas veikts dažas dienas iepriekš. Ķēde pieraksta, kurš debets par ko samaksāja, tā ka vienā pārskatā redzamu pirkumu var izsekot līdz naudai, kas patiešām aizgāja no tava konta. Beatrax pati sasaista drošos gadījumus un pārējos atstāj tev pārskatīšanas rindā. Apstiprini tāda paša veida saiti dažas reizes, un tā pārstāj par šo veidu jautāt.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Norāde ir pusi savienojuma: Beatrax atrada vienu maksājumu ķēdes galu, bet otru ne, tāpēc pierakstīja redzēto, nevis minēja pārējo. Lielākā daļa atrisinās pati — norēķins te gaida, līdz tiek ievesti atsevišķie izdevumi, ko tas apmaksāja, un tad kļūst par īstu ķēdi. Pārējās vari droši noraidīt, un tavos ierakstos tāpat nekas nemainās.',
];
