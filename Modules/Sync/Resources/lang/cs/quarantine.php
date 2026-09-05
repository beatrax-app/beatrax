<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count změnu vytvořila novější verze aplikace Beatrax|:count změny vytvořila novější verze aplikace Beatrax|:count změn vytvořila novější verze aplikace Beatrax',
        'body' => 'To, co bylo odmítnuto, odkazuje na něco, co tato verze aplikace Beatrax nemá, takže to toto zařízení nemělo kam uložit. Zůstává to na zařízení, které to vytvořilo, a nic z toho, co je tvoje, nebylo smazáno.',
        'action' => 'Aktualizuj Beatrax na tomto zařízení. Změny provedené po aktualizaci dorazí normálně, ale nic, co už bylo odmítnuto, se znovu neposílá — pokud změnu potřebuješ i na tomto zařízení, proveď ji tady znovu.',
    ],
    'untrusted_author' => [
        'summary' => ':count změnu podepsalo zařízení, které toto zařízení nezná|:count změny podepsalo zařízení, které toto zařízení nezná|:count změn podepsalo zařízení, které toto zařízení nezná',
        'body' => 'To, co bylo odmítnuto, přišlo ze zařízení, které s tímto nikdy nebylo spárované, nebo ze zařízení, které jsi odebral. Nic se sem nezapsalo a nic z toho, co tu už bylo, se nezměnilo.',
        'action' => 'Pokud jsi to zařízení odebral sám, přesně tohle odebrání dělá a není co opravovat. Pokud ne, podívej se na seznam zařízení na této stránce.',
    ],
    'not_verified' => [
        'summary' => ':count změna neprošla bezpečnostní kontrolou na tomto zařízení|:count změny neprošly bezpečnostní kontrolou na tomto zařízení|:count změn neprošlo bezpečnostní kontrolou na tomto zařízení',
        'body' => 'Podpis neodpovídal zařízení, které tvrdilo, že změnu provedlo, nebo byla změna adresovaná jinému účtu. Nic se sem nezapsalo. Mezi tvými vlastními zařízeními by k tomu docházet nemělo.',
        'action' => 'Podívej se na seznam zařízení na této stránce a odeber vše, co nepoznáváš. Pokud je každé zařízení v seznamu tvoje a děje se to dál, jde o chybu v Beatraxu, ne o něco, co bys odsud mohl spravit.',
    ],
    'diverged' => [
        'summary' => ':count změna z jiného zařízení se sem nedala uložit|:count změny z jiného zařízení se sem nedaly uložit|:count změn z jiného zařízení se sem nedalo uložit',
        'body' => 'Dorazilo něco, co toto zařízení nedokázalo uložit: záznam, kterému chybí část jeho samého, datum, které neexistuje, rozdělení, které už nesedí, záznam, kterému dvě zařízení už přiřadila stejnou identitu, nebo smazání něčeho, co se tu ještě používá. To, co bylo odmítnuto, je na tvém druhém zařízení a na tomto ne, takže obě zařízení už neobsahují totéž.',
        'action' => 'Porovnej záznam na svém druhém zařízení s tím, co vidíš tady, a proveď změnu tady znovu — nebo ji tady znovu smaž, pokud tu pořád je něco, co jsi odstranil jinde. Nic odmítnutého se samo od sebe znovu neposílá.',
    ],
    'last_seen' => 'Nejnovější: :when',
];
