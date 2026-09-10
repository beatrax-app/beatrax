<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Én betaling betaler ofte for flere andre: et kortoppgjør på bankkontoen dekker en måned med kortkjøp, og et uttak fra banken finansierer en lommebokbetaling gjort dager før. En kjede noterer hvilken belastning som betalte hva, slik at et kjøp på én kontoutskrift kan spores tilbake til pengene som faktisk forlot kontoen. Beatrax knytter selv sammen de sikre og lar resten ligge i gjennomgangskøen til deg. Bekreft samme slags kobling noen ganger, så slutter den å spørre om akkurat den slags.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Et hint er en halv kobling: Beatrax fant den ene siden av en betalingskjede og ikke den andre, og skrev ned det den så i stedet for å gjette resten. De fleste løser seg selv — et oppgjør venter her til de enkelte postene det betalte for er importert, og blir da en ekte kjede. Resten kan du trygt avvise, og i regnskapet ditt endres ingenting uansett.',
];
