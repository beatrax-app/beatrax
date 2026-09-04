<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

/**
 * @return list<string>
 */
function pollKeepAliveBladeFiles(): array
{
    $files = [];

    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        if (is_dir($root)) {
            $files = array_merge($files, pollKeepAliveWalk($root));
        }
    }

    sort($files);

    return $files;
}

/**
 * @return list<string>
 */
function pollKeepAliveWalk(string $directory): array
{
    $files = [];

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        // mobile-app/ reaches this same tree through symlinks, and following
        // one reports every shared view a second time under a second spelling.
        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            $files = array_merge($files, pollKeepAliveWalk($path));

            continue;
        }

        if (str_ends_with($path, '.blade.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

/**
 * Blanked rather than deleted, so a poll's reported line number still points at
 * the line the contributor has to edit. Seven of these views explain the poll in
 * prose directly above it, and a scan reading that prose as markup would report
 * an attribute nobody wrote.
 */
function pollKeepAliveStripComments(string $source): string
{
    return PatternScan::replaceCallback(
        '/\{\{--.*?--\}\}/s',
        static fn (array $match): string => PatternScan::replace('/[^\r\n]/', ' ', $match[0]),
        $source,
    );
}

/**
 * @return list<string> one entry per poll a hidden window would throttle
 */
function pollKeepAliveBarePolls(string $source, string $label): array
{
    $hits = [];
    $lines = PatternScan::split('/\R/', pollKeepAliveStripComments($source));

    foreach ($lines as $offset => $line) {
        $matches = PatternScan::sets('/wire:poll((?:\.[A-Za-z0-9-]+)*)/', $line);

        foreach ($matches as $match) {
            if (! str_contains($match[1], '.keep-alive')) {
                $hits[] = $label.':'.($offset + 1).' wire:poll'.$match[1];
            }
        }
    }

    return $hits;
}

it('keeps every poll alive behind a backgrounded window', function (): void {
    $files = pollKeepAliveBladeFiles();
    expect($files)->not->toBe([]);

    $hits = [];
    $polls = 0;

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $label = str_replace(base_path().'/', '', $path);
        $polls += PatternScan::count('/wire:poll/', pollKeepAliveStripComments($source));

        foreach (pollKeepAliveBarePolls($source, $label) as $hit) {
            $hits[] = $hit;
        }
    }

    expect($polls)->toBeGreaterThan(0);
    expect($hits)->toBe([], "Livewire runs a poll on a hidden tab at one tick in twenty — a mean interval of a minute against a stated two or three seconds. That is not a slower refresh, it is a stalled one: a pairing ceremony asks the reader to pick up the other device, and a progress strip is watched by someone who has gone to do something else, so in both cases the window that has to notice is the one guaranteed not to be in front. Add .keep-alive. Offenders:\n  ".implode("\n  ", $hits));
});

it('reads the modifier list rather than the mere presence of a poll', function (): void {
    $bare = '<div wire:poll.3s="checkPairingState">';
    $kept = '<div wire:poll.3s.keep-alive="checkPairingState">';
    $mixed = '<div wire:poll.750ms="a"></div><div wire:poll.2s.keep-alive="b">';
    $modifierless = '<section wire:poll>';

    expect(pollKeepAliveBarePolls($bare, 'v'))->toBe(['v:1 wire:poll.3s']);
    expect(pollKeepAliveBarePolls($kept, 'v'))->toBe([]);
    expect(pollKeepAliveBarePolls($mixed, 'v'))->toBe(['v:1 wire:poll.750ms']);
    expect(pollKeepAliveBarePolls($modifierless, 'v'))->toBe(['v:1 wire:poll']);
});

it('does not read a poll described in a Blade comment as one written in markup', function (): void {
    $described = '{{-- The tile is driven by a wire:poll.2s strip. --}}
<div wire:poll.2s.keep-alive="refresh">';
    $spanning = '{{--
    wire:poll.5s refreshes the whole
    component here.
--}}
<div wire:poll.5s="refresh">';

    expect(pollKeepAliveBarePolls($described, 'v'))->toBe([]);
    expect(pollKeepAliveBarePolls($spanning, 'v'))->toBe(['v:5 wire:poll.5s']);
});
