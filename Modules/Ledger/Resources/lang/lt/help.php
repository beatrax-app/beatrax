<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Suderinimas – tai Beatrax palyginimas su paties banko skaičiumi. Suderintas likutis yra šios sąskaitos pradinis likutis plius kiekviena eilutė, kurią pažymėjai kaip patikrintą iki išrašo datos, o skirtumas yra tavo išrašo skaičius minus tas likutis. Žymėk arba atžymėk eilutes operacijų sąraše, kol skirtumas taps nulis — šis ekranas niekada neišgalvoja išlyginamojo įrašo. Tada „:complete“ užrakina apimtas eilutes: užrakintos eilutės negalima redaguoti, skaidyti ar ištrinti, kol jos vėl neatrakini jos pačios puslapyje.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Kaip eilutė atrodo lyginant su tavo banko išrašu. „:uncleared“ reiškia, kad Beatrax operaciją turi, bet tu jos dar nesugretinai su išrašu; bakstelėk žymą, kad taptų „:cleared“ — būtent šias varneles suderinimo ekranas ir sudeda. „:reconciled“ bakstelėti negalima: jį uždeda baigtas suderinimas, kuris eilutę užrakina, tad kategorija, pastaba, skaidymas ir mokesčių žymos lieka tokios, kokios yra, kol vėl neatrakini.',
];
