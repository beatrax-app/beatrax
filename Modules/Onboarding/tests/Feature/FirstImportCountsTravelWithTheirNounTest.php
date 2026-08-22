<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Blade;
use Modules\Import\Public\Dto\ConsolidatedPreviewBatch;
use Modules\Core\Models\User;
use Modules\Import\Public\Dto\ConsolidatedPreviewSection;
use Modules\Import\Public\Dto\StartingBalanceCandidate;
use Modules\Import\Public\Enums\PreviewSectionStatus;

afterEach(function (): void {
    app()->setLocale('en');
});

// Asserted on the rendered line rather than on Lang::choice, because half of
// what these pin is that the count and its noun arrive as one run of text.
// A numeral the template held rendered the same string and read as finished.
function firstImportHtml(string $locale, int $transactions, int $sources, int $imported = 0): string
{
    app()->setLocale($locale);

    $sections = [];
    foreach (range(1, max($sources, 1)) as $index) {
        $sections[] = new ConsolidatedPreviewSection(
            sourceFormat: 'source-'.$index,
            importRunIds: [$index],
            totalRows: 0,
            sampleRows: [],
            status: PreviewSectionStatus::Empty,
        );
    }

    return app(ViewFactory::class)->make('onboarding::livewire.steps.first-import-step', [
        'preview' => new ConsolidatedPreviewBatch(
            sections: $sources === 0 ? [] : $sections,
            dedupedTotalCount: $transactions,
            alreadyImportedCount: $imported,
        ),
        'startingBalances' => [],
        'balanceConfirmations' => [],
        'commitError' => '',
        'isCommitting' => false,
        'accountMeta' => [],
    ])->render();
}

function firstImportSectionHtml(string $locale, int $rows): string
{
    app()->setLocale($locale);

    return Blade::render('<x-onboarding::consolidated-preview-section :section="$section" />', [
        'section' => new ConsolidatedPreviewSection(
            sourceFormat: 'camt053',
            importRunIds: [1],
            totalRows: $rows,
            sampleRows: [],
            status: PreviewSectionStatus::Empty,
        ),
    ]);
}

it('gives each count in the lede the form its own number selects', function (string $locale, int $transactions, int $sources, string $lede): void {
    expect(firstImportHtml($locale, $transactions, $sources))->toContain($lede);
})->with([
    'en 1/1' => ['en', 1, 1, '1 transaction across 1 source.'],
    'en 2/3' => ['en', 2, 3, '2 transactions across 3 sources.'],

    // One number per selector: 1 transaction is singular in the same line
    // that 5 sources is a genitive plural, which one choice cannot do.
    'cs 1/5' => ['cs', 1, 5, '1 transakce z 5 zdrojů.'],
    'cs 2/1' => ['cs', 2, 1, '2 transakce z 1 zdroje.'],
    'cs 5/2' => ['cs', 5, 2, '5 transakcí z 2 zdrojů.'],
    'cs 21/21' => ['cs', 21, 21, '21 transakcí z 21 zdrojů.'],

    'hr 1/1' => ['hr', 1, 1, '1 transakcija iz 1 izvora.'],
    'hr 2/2' => ['hr', 2, 2, '2 transakcije iz 2 izvora.'],
    'hr 5/5' => ['hr', 5, 5, '5 transakcija iz 5 izvora.'],
    'hr 21/21' => ['hr', 21, 21, '21 transakcija iz 21 izvora.'],

    'pl 1/1' => ['pl', 1, 1, '1 transakcja z 1 źródła.'],
    'pl 2/2' => ['pl', 2, 2, '2 transakcje z 2 źródeł.'],
    'pl 5/5' => ['pl', 5, 5, '5 transakcji z 5 źródeł.'],
    'pl 21/21' => ['pl', 21, 21, '21 transakcji z 21 źródeł.'],

    'sl 1/1' => ['sl', 1, 1, '1 transakcija iz 1 vira.'],
    'sl 2/2' => ['sl', 2, 2, '2 transakciji iz 2 virov.'],
    'sl 3/3' => ['sl', 3, 3, '3 transakcije iz 3 virov.'],
    'sl 5/5' => ['sl', 5, 5, '5 transakcij iz 5 virov.'],

    'uk 1/1' => ['uk', 1, 1, '1 транзакція із 1 джерела.'],
    'uk 2/2' => ['uk', 2, 2, '2 транзакції із 2 джерел.'],
    'uk 5/5' => ['uk', 5, 5, '5 транзакцій із 5 джерел.'],
    'uk 21/21' => ['uk', 21, 21, '21 транзакція із 21 джерела.'],

    'sk 1/2' => ['sk', 1, 2, '1 transakcia z 2 zdrojov.'],
    'sk 5/1' => ['sk', 5, 1, '5 transakcií z 1 zdroja.'],

    'sr 2/5' => ['sr', 2, 5, '2 transakcije iz 5 izvora.'],
    'lt 2/21' => ['lt', 2, 21, '2 operacijos iš 21 šaltinio.'],
    'lt 11/11' => ['lt', 11, 11, '11 operacijų iš 11 šaltinių.'],

    // Latvian selects its first arm for zero, so an empty lede is the only
    // place that arm renders at all.
    'lv 0/0' => ['lv', 0, 0, '0 darījumu no 0 avotiem.'],
    'lv 1/1' => ['lv', 1, 1, '1 darījums no 1 avota.'],
    'lv 21/2' => ['lv', 21, 2, '21 darījums no 2 avotiem.'],

    // Romanian takes "de" from twenty up, and takes it on both counts.
    'ro 1/1' => ['ro', 1, 1, '1 tranzacție din 1 sursă.'],
    'ro 21/21' => ['ro', 21, 21, '21 de tranzacții din 21 de surse.'],

    // French counts zero as singular; English does not.
    'fr 0/0' => ['fr', 0, 0, '0 transaction provenant de 0 source.'],
    'en 0/0' => ['en', 0, 0, '0 transactions across 0 sources.'],

    // Turkish leaves the noun unmarked and Hungarian never marks it after a
    // numeral, so both arms are the same words in both.
    'tr 5/5' => ['tr', 5, 5, '5 işlem — 5 kaynaktan.'],
    'hu 5/5' => ['hu', 5, 5, '5 tranzakció 5 forrásból.'],
    'bg 1/1' => ['bg', 1, 1, '1 транзакция от 1 източник.'],
    'bg 5/5' => ['bg', 5, 5, '5 транзакции от 5 източника.'],

    // Finnish and Estonian put the case on the source noun itself, which is
    // why their frame carries no preposition to hold it.
    'fi 1/1' => ['fi', 1, 1, '1 tapahtuma 1 lähteestä.'],
    'et 2/2' => ['et', 2, 2, '2 tehingut 2 allikast.'],
]);

it('keeps the whole counted phrase inside the emphasis in the commit counter', function (string $locale, int $transactions, int $imported, string $counted, string $already): void {
    $html = firstImportHtml($locale, $transactions, 1, $imported);

    expect($html)->toContain('<strong>'.$counted.'</strong>')
        ->and($html)->toContain($already);
})->with([
    'en 1' => ['en', 1, 1, '1 transaction', '1 already imported'],
    'en 142' => ['en', 142, 3, '142 transactions', '3 already imported'],
    'cs 5' => ['cs', 5, 2, '5 transakcí', '2 už naimportováno'],
    'pl 2' => ['pl', 2, 5, '2 transakcje', '5 już zaimportowano'],
    'sk 1' => ['sk', 1, 1, '1 transakcia', '1 už importovaná'],
    'sk 5' => ['sk', 5, 5, '5 transakcií', '5 už importovaných'],
    'lv 21' => ['lv', 21, 1, '21 darījums', '1 jau importēts'],
    'fr 1' => ['fr', 1, 1, '1 transaction', '1 déjà importée'],
    'fr 2' => ['fr', 2, 2, '2 transactions', '2 déjà importées'],
    'sv 1' => ['sv', 1, 1, '1 transaktion', '1 redan importerad'],
    'ro 21' => ['ro', 21, 21, '21 de tranzacții', '21 de deja importate'],
    'tr 5' => ['tr', 5, 5, '5 işlem', '5 zaten içe aktarıldı'],
]);

it('counts the rows of a preview section inside the eyebrow phrase', function (string $locale, int $rows, string $line): void {
    expect(firstImportSectionHtml($locale, $rows))->toContain($line);
})->with([
    'en 1' => ['en', 1, '· 1 ROW'],
    'en 42' => ['en', 42, '· 42 ROWS'],
    'cs 1' => ['cs', 1, '· 1 ŘÁDEK'],
    'cs 2' => ['cs', 2, '· 2 ŘÁDKY'],
    'cs 5' => ['cs', 5, '· 5 ŘÁDKŮ'],
    'cs 21' => ['cs', 21, '· 21 ŘÁDKŮ'],
    'sl 2' => ['sl', 2, '· 2 VRSTICI'],
    'sl 3' => ['sl', 3, '· 3 VRSTICE'],
    'lv 1' => ['lv', 1, '· 1 RINDA'],
    'lv 21' => ['lv', 21, '· 21 RINDA'],
    'lv 5' => ['lv', 5, '· 5 RINDAS'],
    'ro 21' => ['ro', 21, '· 21 DE RÂNDURI'],
    'uk 5' => ['uk', 5, '· 5 РЯДКІВ'],
    'tr 5' => ['tr', 5, '· 5 SATIR'],
]);

// The balance eyebrow is the one surface whose count reaches a Livewire child,
// so it needs an authenticated reader where the other three do not.
function firstImportBalancesHtml(string $locale, int $accounts): string
{
    app()->setLocale($locale);

    $candidates = [];
    foreach (range(1, $accounts) as $accountId) {
        $candidates[] = new StartingBalanceCandidate(
            accountId: $accountId,
            openingBalanceMinor: 1000,
            openingBalanceDate: '2026-01-01',
            sourceFormat: 'camt053',
        );
    }

    return app(ViewFactory::class)->make('onboarding::livewire.steps.first-import-step', [
        'preview' => new ConsolidatedPreviewBatch(sections: [], dedupedTotalCount: 0, alreadyImportedCount: 0),
        'startingBalances' => $candidates,
        'balanceConfirmations' => [],
        'commitError' => '',
        'isCommitting' => false,
        'accountMeta' => [],
    ])->render();
}

it('counts the detected accounts inside the balance eyebrow phrase', function (string $locale, int $accounts, string $line): void {
    $this->actingAs(User::query()->create([
        'username' => 'first-import-counts',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]));

    expect(firstImportBalancesHtml($locale, $accounts))->toContain('<span class="tabular-nums">'.$line.'</span>');
})->with([
    'en 1' => ['en', 1, '1 ACCOUNT DETECTED'],
    'en 2' => ['en', 2, '2 ACCOUNTS DETECTED'],
    'pl 2' => ['pl', 2, '2 WYKRYTE KONTA'],
    'pl 5' => ['pl', 5, '5 WYKRYTYCH KONT'],
    'sl 2' => ['sl', 2, '2 NAJDENA RAČUNA'],
    'sl 3' => ['sl', 3, '3 NAJDENI RAČUNI'],
    'lv 1' => ['lv', 1, '1 ATKLĀTS KONTS'],
    'lv 21' => ['lv', 21, '21 ATKLĀTS KONTS'],

    // Greek leads with the verb, which is the whole point of the numeral no
    // longer being pinned to the left of the phrase by the template.
    'el 1' => ['el', 1, 'ΕΝΤΟΠΙΣΤΗΚΕ 1 ΛΟΓΑΡΙΑΣΜΟΣ'],
    'el 2' => ['el', 2, 'ΕΝΤΟΠΙΣΤΗΚΑΝ 2 ΛΟΓΑΡΙΑΣΜΟΙ'],
]);
