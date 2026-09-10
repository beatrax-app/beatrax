<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\DevMode\Internal\Enums\CommandTier;

// A run card opens an EventSource and used to close it on one ending: the
// server's `done`. A spawn that crashes, a run past the PHP time limit and a
// restarted server all end the stream without one, and EventSource answers that
// by reconnecting — for the life of the document, against a run that is over.

function runCardMarkup(string $status): RenderedMarkup
{
    return RenderedMarkup::of(Blade::render('<x-dev::run-card :run="$run" />', ['run' => [
        'runId' => 'stream-fixture',
        'command' => 'cache:clear',
        'args' => [],
        'tier' => CommandTier::Safe,
        'status' => $status,
        'startedAt' => '2026-05-17 12:00:00',
        'exitCode' => null,
        'excerpt' => null,
    ]]));
}

it('closes the stream on every ending, not only on the one the server sends', function (): void {
    $mounted = (string) runCardMarkup('running')
        ->firstOrFail('pre[data-run-id="stream-fixture"]')
        ->attribute('x-data');

    expect($mounted)->toContain('new EventSource(');

    // Six connections per origin is the ceiling a browser gives, and the page's
    // own Livewire round-trips are queued behind whatever is holding them.
    expect($mounted)->toContain('onerror');
    expect($mounted)->toContain('destroy()');
    expect($mounted)->toContain('.close()');
});

it('gives each card a key of its own, so a re-render cannot lay one run over another', function (): void {
    expect(runCardMarkup('running')->firstOrFail('article')->attribute('wire:key'))
        ->toBe(
            'run-card-stream-fixture',
            'the timeline is a filtered list, and an unkeyed list is morphed by position: setFilter, rerun and '
            .'spawn could each hand a live stream a different run\'s output',
        );
});
