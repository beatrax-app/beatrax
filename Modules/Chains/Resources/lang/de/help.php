<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Eine Zahlung bezahlt oft mehrere andere: eine Kartenabrechnung auf dem Bankkonto deckt einen Monat Karteneinkäufe ab, und eine Abbuchung der Bank finanziert eine Wallet-Zahlung von vor ein paar Tagen. Eine Kette hält fest, welche Belastung wofür bezahlt hat, sodass ein Einkauf auf dem einen Auszug bis zu dem Geld zurückverfolgt werden kann, das dein Konto wirklich verlassen hat. Beatrax verknüpft die eindeutigen Fälle selbst und lässt den Rest für dich in der Prüfliste. Bestätige dieselbe Art von Verknüpfung ein paar Mal, und es fragt bei dieser Art nicht mehr nach.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Ein Hinweis ist eine halbe Verknüpfung: Beatrax hat die eine Seite einer Zahlungskette gefunden und die andere nicht und deshalb festgehalten, was es gesehen hat, statt den Rest zu raten. Die meisten erledigen sich von selbst — eine Abrechnung wartet hier, bis die Einzelbuchungen eintreffen, die sie bezahlt hat, und wird dann zu einer echten Kette. Den Rest kannst du bedenkenlos verwerfen; an deinen Buchungen ändert sich so oder so nichts.',
];
