<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The header note claimed a category badge read through MerchantMemoryQuery.
// No such markup exists, and the query deliberately discards that result — the
// note described a page nobody had written.

it('renders a badge for every badge the page note names', function (): void {
    $path = dirname(__DIR__, 2).'/Resources/views/livewire/recurring-page.blade.php';
    $source = file_get_contents($path);

    expect($source)->toBeString();

    $note = PatternScan::first('/\{\{--(.*?)--\}\}/s', (string) $source);
    expect($note)->not->toBeEmpty();

    $claims = PatternScan::all('/([A-Za-z]+) badge/', $note[1]);
    expect($claims[1])->not->toBeEmpty();

    $missing = [];
    foreach ($claims[1] as $badge) {
        if (! str_contains((string) $source, 'data-'.mb_strtolower($badge).'-badge')) {
            $missing[] = $badge;
        }
    }

    expect($missing)->toBe([]);
});
