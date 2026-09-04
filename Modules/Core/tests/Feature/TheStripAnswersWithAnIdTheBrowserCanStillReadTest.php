<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Modules\Core\Public\Support\DerivedRowId;

// The strip takes the whole Livewire call as a prop and renders it verbatim
// into a wire:click of its own, so the id inside it is quoted by the caller or
// it is not quoted at all. Goals, Pots and Reports had quoted the button that
// ASKS the question and left the button that ANSWERS it bare — and all three
// mint their rows past 2^53, so the archive and the delete arrived at the
// server naming a row that had been rounded out of existence.
it('renders the id it was handed with every digit the caller wrote', function (): void {
    $id = DerivedRowId::for('goals', ['goal_uuid' => 'the-one-being-archived']);

    expect($id)->toBeGreaterThan(9007199254740991)
        ->and((int) (float) $id)->not->toBe($id, 'this id survives a JS number, so it cannot demonstrate the rounding');

    $template = <<<'BLADE'
        <x-core::confirm-strip question="Q" cancel-label="C" confirm-label="Y" cancel="cancelArchive" :confirm="'archive(\''.$id.'\')'" />
        BLADE;

    $html = Blade::render($template, ['id' => $id]);

    // &#039; is what {{ }} makes of the quote, and the browser reads it back as
    // one — the argument reaches Livewire as a string either way.
    expect($html)->toContain('wire:click="archive(&#039;'.$id.'&#039;)"')
        ->and($html)->not->toContain('wire:click="archive('.$id.')"');
});
