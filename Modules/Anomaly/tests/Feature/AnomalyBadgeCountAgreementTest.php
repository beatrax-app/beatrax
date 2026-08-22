<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactory;

afterEach(function (): void {
    app()->setLocale('en');
});

// The view is rendered directly rather than through Livewire because the
// defect is in the copy the Blade assembles, and a locale matrix this wide
// would otherwise pay for a fixture graph per row.
function anomalyBadgeHtml(string $locale, int $count): string
{
    app()->setLocale($locale);

    return app(ViewFactory::class)->make('anomaly::livewire.dashboard-anomaly-badge', [
        'openCount' => $count,
        'breakdown' => ['large' => $count, 'first_time' => $count, 'duplicate' => $count],
    ])->render();
}

it('agrees every part of the breakdown line with the number beside it', function (string $locale, int $count, string $line): void {
    expect(anomalyBadgeHtml($locale, $count))->toContain($line);
})->with([
    'en 1' => ['en', 1, '1 open · 1 large · 1 first-time · 1 duplicate'],
    'en 2' => ['en', 2, '2 open · 2 large · 2 first-time · 2 duplicate'],

    'bg 1' => ['bg', 1, '1 отворено · 1 голямо · 1 за първи път · 1 дублирано'],
    'bg 2' => ['bg', 2, '2 отворени · 2 големи · 2 за първи път · 2 дублирани'],

    'cs 1' => ['cs', 1, '1 otevřená · 1 velká · 1 poprvé · 1 duplicitní'],
    'cs 2' => ['cs', 2, '2 otevřené · 2 velké · 2 poprvé · 2 duplicitní'],
    'cs 5' => ['cs', 5, '5 otevřených · 5 velkých · 5 poprvé · 5 duplicitních'],
    'cs 21' => ['cs', 21, '21 otevřených · 21 velkých · 21 poprvé · 21 duplicitních'],

    'da 1' => ['da', 1, '1 åben · 1 stor · 1 første gang · 1 dublet'],
    'da 2' => ['da', 2, '2 åbne · 2 store · 2 første gang · 2 dubletter'],

    'de 1' => ['de', 1, '1 offene · 1 große · 1 zum ersten Mal · 1 doppelte'],
    'de 2' => ['de', 2, '2 offene · 2 große · 2 zum ersten Mal · 2 doppelte'],

    'el 1' => ['el', 1, '1 σε εκκρεμότητα · 1 μεγάλη · 1 πρώτη φορά · 1 διπλότυπη'],
    'el 2' => ['el', 2, '2 σε εκκρεμότητα · 2 μεγάλες · 2 πρώτη φορά · 2 διπλότυπες'],

    'es 1' => ['es', 1, '1 abierto · 1 elevado · 1 por primera vez · 1 duplicado'],
    'es 2' => ['es', 2, '2 abiertos · 2 elevados · 2 por primera vez · 2 duplicados'],

    'et 1' => ['et', 1, '1 lahtine · 1 suur · 1 esmakordne · 1 duplikaat'],
    'et 2' => ['et', 2, '2 lahtist · 2 suurt · 2 esmakordset · 2 duplikaati'],

    'fi 1' => ['fi', 1, '1 avoin · 1 suuri · 1 ensimmäinen kerta · 1 kaksoisveloitus'],
    'fi 2' => ['fi', 2, '2 avointa · 2 suurta · 2 ensimmäistä kertaa · 2 kaksoisveloitusta'],

    'fr 1' => ['fr', 1, '1 en cours · 1 important · 1 première fois · 1 doublon'],
    'fr 2' => ['fr', 2, '2 en cours · 2 importants · 2 premières fois · 2 doublons'],

    'hr 1' => ['hr', 1, '1 otvoreno · 1 veliko · 1 prvi put · 1 dvostruko'],
    'hr 2' => ['hr', 2, '2 otvorena · 2 velika · 2 prvi put · 2 dvostruka'],
    'hr 5' => ['hr', 5, '5 otvorenih · 5 velikih · 5 prvi put · 5 dvostrukih'],
    'hr 21' => ['hr', 21, '21 otvoreno · 21 veliko · 21 prvi put · 21 dvostruko'],
    'hr 22' => ['hr', 22, '22 otvorena · 22 velika · 22 prvi put · 22 dvostruka'],

    'hu 1' => ['hu', 1, '1 nyitott · 1 nagy összegű · 1 első alkalom · 1 duplikátum'],
    'hu 2' => ['hu', 2, '2 nyitott · 2 nagy összegű · 2 első alkalom · 2 duplikátum'],

    'it 1' => ['it', 1, '1 aperto · 1 elevato · 1 prima volta · 1 duplicato'],
    'it 2' => ['it', 2, '2 aperti · 2 elevati · 2 prime volte · 2 duplicati'],

    'lt 1' => ['lt', 1, '1 neperžiūrėtas · 1 didelis · 1 pirmą kartą · 1 dublikatas'],
    'lt 2' => ['lt', 2, '2 neperžiūrėti · 2 dideli · 2 pirmą kartą · 2 dublikatai'],
    'lt 11' => ['lt', 11, '11 neperžiūrėtų · 11 didelių · 11 pirmą kartą · 11 dublikatų'],
    'lt 21' => ['lt', 21, '21 neperžiūrėtas · 21 didelis · 21 pirmą kartą · 21 dublikatas'],

    'lv 1' => ['lv', 1, '1 atvērts · 1 liels · 1 pirmreizējs · 1 dublikāts'],
    'lv 2' => ['lv', 2, '2 atvērti · 2 lieli · 2 pirmreizēji · 2 dublikāti'],
    'lv 21' => ['lv', 21, '21 atvērts · 21 liels · 21 pirmreizējs · 21 dublikāts'],

    'nb 1' => ['nb', 1, '1 åpen · 1 stor · 1 første gang · 1 duplikat'],
    'nb 2' => ['nb', 2, '2 åpne · 2 store · 2 første gang · 2 duplikater'],

    'nl 1' => ['nl', 1, '1 openstaande · 1 grote · 1 eerste keer · 1 dubbele'],
    'nl 2' => ['nl', 2, '2 openstaande · 2 grote · 2 eerste keer · 2 dubbele'],

    'pl 1' => ['pl', 1, '1 otwarte · 1 duże · 1 po raz pierwszy · 1 zduplikowane'],
    'pl 2' => ['pl', 2, '2 otwarte · 2 duże · 2 po raz pierwszy · 2 zduplikowane'],
    'pl 5' => ['pl', 5, '5 otwartych · 5 dużych · 5 po raz pierwszy · 5 zduplikowanych'],
    'pl 21' => ['pl', 21, '21 otwartych · 21 dużych · 21 po raz pierwszy · 21 zduplikowanych'],
    'pl 22' => ['pl', 22, '22 otwarte · 22 duże · 22 po raz pierwszy · 22 zduplikowane'],

    'pt 1' => ['pt', 1, '1 em aberto · 1 elevada · 1 primeira vez · 1 duplicada'],
    'pt 2' => ['pt', 2, '2 em aberto · 2 elevadas · 2 primeiras vezes · 2 duplicadas'],

    'ro 1' => ['ro', 1, '1 deschisă · 1 mare · 1 prima dată · 1 duplicat'],
    'ro 2' => ['ro', 2, '2 deschise · 2 mari · 2 prima dată · 2 duplicate'],
    'ro 21' => ['ro', 21, '21 de deschise · 21 de mari · 21 prima dată · 21 de duplicate'],

    'sk 1' => ['sk', 1, '1 otvorená · 1 veľká · 1 prvýkrát · 1 duplicitná'],
    'sk 2' => ['sk', 2, '2 otvorené · 2 veľké · 2 prvýkrát · 2 duplicitné'],
    'sk 5' => ['sk', 5, '5 otvorených · 5 veľkých · 5 prvýkrát · 5 duplicitných'],

    'sl 1' => ['sl', 1, '1 odprta · 1 velika · 1 prvič · 1 podvojena'],
    'sl 2' => ['sl', 2, '2 odprti · 2 veliki · 2 prvič · 2 podvojeni'],
    'sl 3' => ['sl', 3, '3 odprte · 3 velike · 3 prvič · 3 podvojene'],
    'sl 5' => ['sl', 5, '5 odprtih · 5 velikih · 5 prvič · 5 podvojenih'],
    'sl 101' => ['sl', 101, '101 odprta · 101 velika · 101 prvič · 101 podvojena'],

    'sr 1' => ['sr', 1, '1 otvoreno · 1 veliko · 1 prvi put · 1 duplirano'],
    'sr 2' => ['sr', 2, '2 otvorena · 2 velika · 2 prvi put · 2 duplirana'],
    'sr 5' => ['sr', 5, '5 otvorenih · 5 velikih · 5 prvi put · 5 dupliranih'],
    'sr 21' => ['sr', 21, '21 otvoreno · 21 veliko · 21 prvi put · 21 duplirano'],

    'sv 1' => ['sv', 1, '1 öppen · 1 stor · 1 första gången · 1 dubblett'],
    'sv 2' => ['sv', 2, '2 öppna · 2 stora · 2 första gången · 2 dubbletter'],

    'tr 1' => ['tr', 1, '1 açık · 1 yüksek · 1 ilk kez · 1 yinelenen'],
    'tr 5' => ['tr', 5, '5 açık · 5 yüksek · 5 ilk kez · 5 yinelenen'],

    'uk 1' => ['uk', 1, '1 відкрите · 1 велике · 1 уперше · 1 дубльоване'],
    'uk 2' => ['uk', 2, '2 відкриті · 2 великі · 2 уперше · 2 дубльовані'],
    'uk 5' => ['uk', 5, '5 відкритих · 5 великих · 5 уперше · 5 дубльованих'],
    'uk 21' => ['uk', 21, '21 відкрите · 21 велике · 21 уперше · 21 дубльоване'],
]);

it('carries the same inflected open phrase into the accessible name', function (string $locale, int $count, string $ariaLabel): void {
    expect(anomalyBadgeHtml($locale, $count))->toContain('aria-label="'.$ariaLabel.'"');
})->with([
    'en 5' => ['en', 5, 'Unusual charges — 5 open'],
    'pl 5' => ['pl', 5, 'Nietypowe obciążenia — 5 otwartych'],
    'pl 2' => ['pl', 2, 'Nietypowe obciążenia — 2 otwarte'],
    'sl 3' => ['sl', 3, 'Nenavadne bremenitve — 3 odprte'],
    'lv 21' => ['lv', 21, 'Neparasti maksājumi — 21 atvērts'],
    'tr 5' => ['tr', 5, 'Olağan dışı harcamalar — 5 açık'],
]);

it('leaves a detector out of the line entirely when its count is zero', function (): void {
    app()->setLocale('pl');

    $html = app(ViewFactory::class)->make('anomaly::livewire.dashboard-anomaly-badge', [
        'openCount' => 5,
        'breakdown' => ['large' => 0, 'first_time' => 2, 'duplicate' => 0],
    ])->render();

    expect($html)->toContain('5 otwartych · 2 po raz pierwszy')
        ->and($html)->not->toContain('dużych')
        ->and($html)->not->toContain('zduplikowan');
});
