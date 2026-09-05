<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count wijziging is gemaakt door een nieuwere versie van Beatrax|:count wijzigingen zijn gemaakt door een nieuwere versie van Beatrax',
        'body' => 'Wat geweigerd is, verwijst naar iets dat deze versie van Beatrax niet heeft, dus dit apparaat kon het nergens kwijt. Het staat nog op het apparaat dat het heeft gemaakt, en er is niets van jou verwijderd.',
        'action' => 'Werk Beatrax op dit apparaat bij. Wijzigingen van na de update komen gewoon binnen, maar wat eenmaal geweigerd is, wordt niet opnieuw verstuurd — maak de wijziging hier opnieuw als je die ook op dit apparaat nodig hebt.',
    ],
    'untrusted_author' => [
        'summary' => ':count wijziging is ondertekend door een apparaat dat dit apparaat niet herkent|:count wijzigingen zijn ondertekend door een apparaat dat dit apparaat niet herkent',
        'body' => 'Wat geweigerd is, kwam van een apparaat dat nooit met dit apparaat is gekoppeld, of van een apparaat dat je hebt verwijderd. Er is hier niets weggeschreven en er is niets veranderd aan wat hier al stond.',
        'action' => 'Als je dat apparaat zelf hebt verwijderd, is dit precies wat verwijderen doet en valt er niets te herstellen. Zo niet, bekijk dan de lijst met apparaten op deze pagina.',
    ],
    'not_verified' => [
        'summary' => ':count wijziging kwam niet door de veiligheidscontrole op dit apparaat|:count wijzigingen kwamen niet door de veiligheidscontrole op dit apparaat',
        'body' => 'Een handtekening kwam niet overeen met het apparaat dat zei de wijziging te hebben gemaakt, of de wijziging was gericht aan een ander account. Er is hier niets weggeschreven. Tussen je eigen apparaten hoort dit niet te gebeuren.',
        'action' => 'Bekijk de lijst met apparaten op deze pagina en verwijder alles wat je niet herkent. Als elk apparaat daar van jou is en dit blijft gebeuren, is het een fout in Beatrax en niet iets dat je hier kunt verhelpen.',
    ],
    'diverged' => [
        'summary' => ':count wijziging van een ander apparaat kon hier niet worden opgeslagen|:count wijzigingen van een ander apparaat konden hier niet worden opgeslagen',
        'body' => 'Er kwam iets binnen dat dit apparaat niet kon opslaan: een record waaraan een deel ontbreekt, een datum die niet bestaat, een splitsing die niet meer klopt, een record waaraan twee apparaten al dezelfde identiteit hadden gegeven, of een verwijdering van iets dat hier nog in gebruik is. Wat geweigerd is, staat op je andere apparaat en niet op dit apparaat, dus de twee bevatten niet langer hetzelfde.',
        'action' => 'Vergelijk het record op je andere apparaat met wat je hier ziet en voer de wijziging hier opnieuw door — of verwijder het hier opnieuw, als iets dat je elders hebt verwijderd hier nog staat. Wat geweigerd is, wordt niet vanzelf opnieuw verstuurd.',
    ],
    'last_seen' => 'Meest recent: :when',
];
