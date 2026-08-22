<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Collection;
use Modules\Reports\Internal\Actions\TogglePin;
use Modules\Reports\Internal\Dto\SavedReportIndexRow;

afterEach(function (): void {
    app()->setLocale('en');
});

// Rendered from the view rather than the Livewire component so the pinned
// tally can be driven straight to 0 and to the cap without three write
// actions and a database row per case.
function pinnedRatioHtml(string $locale, int $pinnedCount, int $rowCount = 3): string
{
    app()->setLocale($locale);

    $rows = new Collection(array_map(
        static fn (int $id): SavedReportIndexRow => new SavedReportIndexRow(
            id: $id,
            name: 'Report '.$id,
            summary: 'Spend',
            pinned: $id <= $pinnedCount,
            pinOrder: $id <= $pinnedCount ? $id : null,
        ),
        range(1, max($rowCount, 1)),
    ));

    return app(ViewFactory::class)->make('reports::livewire.reports-index', [
        'rows' => $rows,
        'activeView' => 'cards',
        'pinnedCount' => $pinnedCount,
        'flashMessage' => '',
        'confirmingDeleteId' => null,
    ])->render();
}

it('reads the pinned tally as one phrase that owns both of its numbers', function (string $locale, int $pinned, string $phrase): void {
    expect(pinnedRatioHtml($locale, $pinned))->toContain($phrase);
})->with([
    'en 0' => ['en', 0, '0 of 3 pinned'],
    'en 1' => ['en', 1, '1 of 3 pinned'],
    'en 2' => ['en', 2, '2 of 3 pinned'],
    'en 3' => ['en', 3, '3 of 3 pinned'],

    'bg 1' => ['bg', 1, '1 от 3 закачен'],
    'bg 2' => ['bg', 2, '2 от 3 закачени'],

    'cs 0' => ['cs', 0, '0 z 3 připnutých'],
    'cs 1' => ['cs', 1, '1 z 3 připnutá'],
    'cs 2' => ['cs', 2, '2 z 3 připnuté'],
    'cs 3' => ['cs', 3, '3 z 3 připnuté'],

    'da 1' => ['da', 1, '1 af 3 fastgjort'],
    'da 2' => ['da', 2, '2 af 3 fastgjort'],

    'de 1' => ['de', 1, '1 von 3 angeheftet'],
    'de 2' => ['de', 2, '2 von 3 angeheftet'],

    'el 1' => ['el', 1, '1 από 3 καρφιτσωμένη'],
    'el 2' => ['el', 2, '2 από 3 καρφιτσωμένες'],

    'es 1' => ['es', 1, '1 de 3 fijado'],
    'es 2' => ['es', 2, '2 de 3 fijados'],

    'et 2' => ['et', 2, '2/3 kinnitatud'],
    'fi 2' => ['fi', 2, '2/3 kiinnitetty'],

    'fr 0' => ['fr', 0, '0 sur 3 épinglé'],
    'fr 1' => ['fr', 1, '1 sur 3 épinglé'],
    'fr 2' => ['fr', 2, '2 sur 3 épinglés'],

    'hr 0' => ['hr', 0, '0 od 3 prikvačenih'],
    'hr 1' => ['hr', 1, '1 od 3 prikvačeno'],
    'hr 2' => ['hr', 2, '2 od 3 prikvačena'],

    'hu 2' => ['hu', 2, '2/3 rögzítve'],

    'it 1' => ['it', 1, '1 su 3 fissato'],
    'it 2' => ['it', 2, '2 su 3 fissati'],

    'lt 0' => ['lt', 0, '0 iš 3 prisegtų'],
    'lt 1' => ['lt', 1, '1 iš 3 prisegta'],
    'lt 2' => ['lt', 2, '2 iš 3 prisegtos'],

    'lv 0' => ['lv', 0, '0 no 3 piespraustu'],
    'lv 1' => ['lv', 1, '1 no 3 piesprausta'],
    'lv 2' => ['lv', 2, '2 no 3 piespraustas'],

    'nb 2' => ['nb', 2, '2 av 3 festet'],
    'nl 2' => ['nl', 2, '2 van 3 vastgezet'],

    'pl 0' => ['pl', 0, '0 z 3 przypiętych'],
    'pl 1' => ['pl', 1, '1 z 3 przypięty'],
    'pl 2' => ['pl', 2, '2 z 3 przypięte'],
    'pl 3' => ['pl', 3, '3 z 3 przypięte'],

    'pt 1' => ['pt', 1, '1 de 3 fixado'],
    'pt 2' => ['pt', 2, '2 de 3 fixados'],

    'ro 1' => ['ro', 1, '1 din 3 fixat'],
    'ro 2' => ['ro', 2, '2 din 3 fixate'],

    'sk 0' => ['sk', 0, '0 z 3 pripnutých'],
    'sk 1' => ['sk', 1, '1 z 3 pripnutá'],
    'sk 2' => ['sk', 2, '2 z 3 pripnuté'],

    'sl 0' => ['sl', 0, '0 od 3 pripetih'],
    'sl 1' => ['sl', 1, '1 od 3 pripeto'],
    'sl 2' => ['sl', 2, '2 od 3 pripeti'],
    'sl 3' => ['sl', 3, '3 od 3 pripeta'],

    'sr 0' => ['sr', 0, '0 od 3 zakačenih'],
    'sr 1' => ['sr', 1, '1 od 3 zakačen'],
    'sr 2' => ['sr', 2, '2 od 3 zakačena'],

    'sv 1' => ['sv', 1, '1 av 3 fäst'],
    'sv 2' => ['sv', 2, '2 av 3 fästa'],

    'tr 2' => ['tr', 2, '2/3 sabitlenmiş'],

    'uk 0' => ['uk', 0, '0 з 3 закріплених'],
    'uk 1' => ['uk', 1, '1 з 3 закріплений'],
    'uk 2' => ['uk', 2, '2 з 3 закріплені'],
]);

it('assembles the whole meta line from two independently chosen phrases', function (string $locale, int $pinned, string $line): void {
    expect(pinnedRatioHtml($locale, $pinned))->toContain($line);
})->with([
    'en' => ['en', 2, '3 saved reports · 2 of 3 pinned'],
    'pl' => ['pl', 2, '3 zapisane raporty · 2 z 3 przypięte'],
    'cs' => ['cs', 1, '3 uložené sestavy · 1 z 3 připnutá'],
    'sl' => ['sl', 3, '3 shranjena poročila · 3 od 3 pripeta'],
]);

it('takes the cap in the tally from the constant the pin action enforces', function (): void {
    expect(file_get_contents(base_path('Modules/Reports/Resources/views/livewire/reports-index.blade.php')))
        ->toContain('TogglePin::MAX_PINS')
        ->and(pinnedRatioHtml('en', 1))->toContain('1 of '.TogglePin::MAX_PINS.' pinned');
});
