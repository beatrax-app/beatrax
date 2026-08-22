<?php

declare(strict_types=1);

use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;

// counterparties.slug is a synced plaintext key, and //TRANSLIT is the C
// library's answer rather than PHP's: macOS libiconv, glibc and musl each
// derived a different slug for 24 of these 41 names, so two devices in one
// household keyed the same merchant apart. The pairs below are the derived
// value, not a shape — a change to any of them forks a stored row.

it('derives one slug for an accented European merchant name', function (string $name, string $slug): void {
    expect(CounterpartySlugResolver::slugify($name))->toBe($slug);
})->with([
    ['Café Ambiance', 'cafe-ambiance'],
    ['Crédit Agricole', 'credit-agricole'],
    ['Société Générale', 'societe-generale'],
    ['Müller', 'muller'],
    ['Kärcher', 'karcher'],
    ['Größe GmbH', 'grosse-gmbh'],
    ['Straße 12', 'strasse-12'],
    ['Señor Tapas', 'senor-tapas'],
    ['España Viajes', 'espana-viajes'],
    ['Łódź Market', 'lodz-market'],
    ['Kraków Deli', 'krakow-deli'],
    ['Żabka', 'zabka'],
    ['Škoda Auto', 'skoda-auto'],
    ['Dvořák Café', 'dvorak-cafe'],
    ['Plzeň Pivo', 'plzen-pivo'],
    ['Beşiktaş Market', 'besiktas-market'],
    ['Işık Döner', 'isik-doner'],
    ['Ünlü Şarküteri', 'unlu-sarkuteri'],
    ['Ångström AB', 'angstrom-ab'],
    ['Håkan Bil', 'hakan-bil'],
    ['Blåbær', 'blabaer'],
    ['Ærø Færge', 'aero-faerge'],
    ['Þórr', 'thorr'],
    ['Jörgen', 'jorgen'],
    ['Zoë Nails', 'zoe-nails'],
    ['Renée Coiffure', 'renee-coiffure'],
    ['Œuvre', 'oeuvre'],
    ['Ægir Bryggeri', 'aegir-bryggeri'],
    ['Đorđe', 'dorde'],
    ['Ffanø', 'ffano'],
]);

// The scripts no transliterator agrees on. Everything that is not a Latin
// letter after compatibility decomposition is dropped rather than romanised,
// which is what //IGNORE already did — romanising them would rename every
// stored Cyrillic and Greek merchant.
it('derives one slug for a name outside the Latin script', function (string $name, string $slug): void {
    expect(CounterpartySlugResolver::slugify($name))->toBe($slug);
})->with([
    ['Пятёрочка', 'counterparty'],
    ['Αθήνα Market', 'market'],
    ['Ωμέγα', 'counterparty'],
    ['北京餐厅', 'counterparty'],
    ['Zeta Ωmega', 'zeta-mega'],
    ['½ Portion', '1-2-portion'],
    ['Ⅻ Roman', 'xii-roman'],
    ['ＦＵＬＬＷＩＤＴＨ', 'fullwidth'],
    ['ﬁ Ligature', 'fi-ligature'],
]);

// The symbols and punctuation //TRANSLIT spelled with a letter or a word
// break. voku/portable-ascii has no entry for most of them, so an unspelled
// character becomes a separator here and the seven it spelled with a letter
// are named on the resolver.
it('keeps the spelling every libc already agreed on for a symbol', function (string $name, string $slug): void {
    expect(CounterpartySlugResolver::slugify($name))->toBe($slug);
})->with([
    ['Disney© Store', 'disney-c-store'],
    ['Acme® Ltd', 'acme-r-ltd'],
    ['3 × Espresso', '3-x-espresso'],
    ['Prijs € 10', 'prijs-eur-10'],
    ['Jan–Pieter Bakkerij', 'jan-pieter-bakkerij'],
    ['Café • Bar', 'cafe-o-bar'],
    ['5 µm Optics', '5-um-optics'],
    ['Paral·lel 62', 'paral-lel-62'],
    ['Zeta Ωmega', 'zeta-mega'],
    ["Bol\u{200B}Shop", 'bolshop'],
]);
