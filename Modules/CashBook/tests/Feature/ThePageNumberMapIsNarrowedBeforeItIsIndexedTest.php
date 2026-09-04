<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\PatternScan;

uses(RefreshDatabase::class);

// WithPagination's $paginators is public and untyped, so a wire payload could
// put a scalar where getPage() indexes an array and setPage() assigns into one.
// Both are fatal, and both were reachable on the cash book with a valid
// snapshot. #[Locked] is not the answer here: the browser's Back button
// legitimately writes paginators.page, so the map is narrowed instead.

function pageMapSnapshot(string $pageHtml): string
{
    $matches = PatternScan::all('/wire:snapshot="([^"]*)"/', $pageHtml);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"cashbook.cash-book-page"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the cash book on the rendered page.');
}

/**
 * @param  array<string, mixed>  $updates
 * @param  list<array{path: string, method: string, params: list<mixed>}>  $calls
 */
function pageMapTamper(string $snapshot, array $updates, array $calls = []): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => $calls,
        ]],
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'page-map-narrowing',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('answers a page map replaced by a scalar without a server fault', function (): void {
    $snapshot = pageMapSnapshot($this->get('/cash')->assertOk()->getContent());

    pageMapTamper($snapshot, ['paginators' => 'zzz'])->assertOk();
});

it('answers a scalar page map followed by a page turn without a server fault', function (): void {
    $snapshot = pageMapSnapshot($this->get('/cash')->assertOk()->getContent());

    pageMapTamper(
        $snapshot,
        ['paginators' => -1],
        [['path' => '', 'method' => 'nextPage', 'params' => []]],
    )->assertOk();
});

// The narrowing must not be a blanket reset: the Back button writes this exact
// path, and a fix that dropped it would leave every later page unreachable.
it('still reaches the second page from the page number the browser writes', function (): void {
    $component = Livewire::test(CashBookPage::class);
    for ($i = 1; $i <= 26; $i++) {
        $component->set('amount', '2,50')
            ->set('date', '2026-06-05')
            ->set('counterparty', 'Kiosk')
            ->call('add')
            ->assertDispatched('toast', message: Lang::get('cashbook::cash-book.toast.added'));
    }

    $oldest = (int) DB::table('transactions')
        ->where('user_id', $this->user->id)
        ->where('source_format', 'manual')
        ->orderBy('posted_at')
        ->orderBy('id')
        ->value('id');

    $snapshot = pageMapSnapshot($this->get('/cash')->assertOk()->getContent());

    $html = pageMapTamper($snapshot, ['paginators.page' => '2'])
        ->assertOk()
        ->json('components.0.effects.html');

    expect(is_string($html))->toBeTrue()
        ->and($html)->toContain('wire:key="manual-'.$oldest.'"');
});
