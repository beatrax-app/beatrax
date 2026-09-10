<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Zgoda, jakiej twój bank udzielił Beatraxowi na odczyt tego konta. Banki muszą pozwolić jej wygasnąć: ta zgoda trwa 180 dni, dwa tygodnie wcześniej status zmienia się na „:expiring”, a dopóki wygasła, nic nie jest pobierane — „:reconnect” loguje cię w banku ponownie i uruchamia nowe 180 dni. Bank może ją też zakończyć wcześniej z własnej aplikacji, a to, co już zaimportowano, w żadnym wypadku nie ginie.',
];
