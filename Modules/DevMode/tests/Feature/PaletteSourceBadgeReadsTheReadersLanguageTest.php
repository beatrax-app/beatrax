<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Locale;
use Modules\DevMode\Internal\Enums\PaletteSource;
use Modules\DevMode\Internal\Http\Livewire\CommandPaletteModal;
use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Contracts\NavigationRegistry;

// The badge beside every palette row printed the row's own registry key —
// `view`, `dev-view`, `dev`, `action` — in every language, while the txn and
// counterparty chips next to it were translated. The key is a wire value that
// also names a CSS class, so it has to stay; the reader needs the other one.

beforeEach(function (): void {
    $this->reader = User::query()->create([
        'username' => 'palette-language-reader',
        'password' => 'fixture-password',
        'is_developer' => true,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $contract = $this->createStub(CurrentUser::class);
    $contract->method('isAuthenticated')->willReturn(true);
    $contract->method('user')->willReturn($this->reader);
    $contract->method('id')->willReturn($this->reader->id);
    app()->instance(CurrentUser::class, $contract);
});

/**
 * @return list<array<string, mixed>>
 */
function paletteRegistryRows(): array
{
    /** @var CommandPaletteModal $component */
    $component = Livewire::test(CommandPaletteModal::class);

    return $component->instance()->buildRegistry(
        app(CurrentUser::class),
        app(NavigationRegistry::class),
        app(DevCommandRegistry::class),
        app(AppActionRegistry::class),
    );
}

it('gives every row a label beside the key it keeps for the stylesheet', function (): void {
    $rows = paletteRegistryRows();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row)->toHaveKey('sourceLabel')
            ->and(PaletteSource::tryFrom(is_string($row['source'] ?? null) ? $row['source'] : ''))
            ->not->toBeNull()
            ->and($row['sourceLabel'])->not->toBe($row['source']);
    }
});

// The defect, stated as a test: a Dutch reader saw the English registry key.
it('translates the badge for a reader who is not reading English', function (): void {
    app()->setLocale(Locale::Nl->value);

    $labels = array_values(array_unique(array_map(
        static fn (array $row): string => is_string($row['sourceLabel'] ?? null) ? $row['sourceLabel'] : '',
        paletteRegistryRows(),
    )));

    expect($labels)->toContain('Weergave')
        ->and($labels)->not->toContain('view')
        ->and($labels)->not->toContain('dev-view');
});

// Each of the three rails the palette actually offers, so a fourth source added
// later cannot quietly fall through to the wrong heading.
it('labels every source with the rail it belongs to', function (): void {
    app()->setLocale(Locale::En->value);

    expect(PaletteSource::View->label())->toBe('View')
        ->and(PaletteSource::DevView->label())->toBe('Dev')
        ->and(PaletteSource::Dev->label())->toBe('Dev')
        ->and(PaletteSource::Action->label())->toBe('Action');
});

// A recent pick is replayed out of client storage, so its source is whatever
// was stored — including a value this build no longer knows.
it('falls back to a real source when a recent pick carries an unknown one', function (): void {
    $recent = Livewire::test(CommandPaletteModal::class)
        ->call('pickEntry', ['id' => 'nav.dashboard', 'label' => 'Dashboard', 'source' => 'not-a-source'])
        ->get('recent');

    expect($recent)->not->toBeEmpty();

    $sources = array_map(
        static fn (array $row): string => is_string($row['source'] ?? null) ? $row['source'] : '',
        array_values($recent),
    );

    foreach ($sources as $source) {
        expect(PaletteSource::tryFrom($source))->not->toBeNull();
    }
});
